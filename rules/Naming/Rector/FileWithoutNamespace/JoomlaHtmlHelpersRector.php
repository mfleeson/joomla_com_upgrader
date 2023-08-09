<?php
/**
 * Joomla 3 Component Upgrade Rectors
 *
 * @copyright  2022 Nicholas K. Dionysopoulos
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

declare (strict_types=1);

namespace Rector\Naming\Rector\FileWithoutNamespace;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use Rector\Core\Application\FileSystem\RemovedAndAddedFilesCollector;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Reflection\ReflectionResolver;
use Rector\Privatization\NodeManipulator\VisibilityManipulator;
use Rector\Privatization\VisibilityGuard\ClassMethodVisibilityGuard;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * A Rector rule to convert Joomla 3 Html helpers with static methods to Joomla 4 HTML Helper objects with non-static
 * methods **and** namespace them.
 *
 * @since  1.0.0
 * @see    \Rector\Tests\Naming\Rector\FileWithoutNamespace\JoomlaHtmlHelpersRector\JoomlaHtmlHelpersRectorTest
 */
final class JoomlaHtmlHelpersRector extends JoomlaLegacyMVCToJ4Rector implements ConfigurableRectorInterface
{
	use JoomlaNamespaceHandlingTrait;

	/**
	 * @readonly
	 * @var \Rector\Privatization\VisibilityGuard\ClassMethodVisibilityGuard
	 */
	private $classMethodVisibilityGuard;

	/**
	 * @readonly
	 * @var \Rector\Core\Reflection\ReflectionResolver
	 */
	private $reflectionResolver;

	/**
	 * @readonly
	 * @var \Rector\Privatization\NodeManipulator\VisibilityManipulator
	 */
	private $visibilityManipulator;

	public function __construct(
		JoomlaRemovedAndAddedFilesCollector $removedAndAddedFilesCollector,
		RenamedClassHandlerService    $renamedClassHandlerService,
		ClassMethodVisibilityGuard    $classMethodVisibilityGuard,
		VisibilityManipulator         $visibilityManipulator,
		ReflectionResolver            $reflectionResolver
	)
	{
		parent::__construct($removedAndAddedFilesCollector, $renamedClassHandlerService);

		$this->classMethodVisibilityGuard = $classMethodVisibilityGuard;
		$this->visibilityManipulator      = $visibilityManipulator;
		$this->reflectionResolver         = $reflectionResolver;
	}

	public function getNodeTypes(): array
	{
		return array_merge(parent::getNodeTypes(), [Class_::class, ClassMethod::class, StaticCall::class]);
	}

	/**
	 * Get the rule definition.
	 *
	 * This was used to generate the initial test fixture.
	 *
	 * @return  RuleDefinition
	 * @throws  \Symplify\RuleDocGenerator\Exception\PoorDocumentationException
	 * @since   1.0.0
	 */
	public function getRuleDefinition(): RuleDefinition
	{
		return new RuleDefinition('Convert legacy Joomla 3 HTML Helper class names into Joomla 4 namespaced ones.', [
			new CodeSample(
				<<<'CODE_SAMPLE'
abstract class JHtmlExample
{
	static function derp(): string
	{
		return "derp";
	}
}
CODE_SAMPLE
				, <<<'CODE_SAMPLE'
namespace Acme\Example\Administrator\Service\HTML;

class Example
{
	function derp(): string
	{
		return "derp";
	}
}
CODE_SAMPLE
			),
		]);
	}

	public function refactor(Node $node): ?Node
	{
		// Makes sure the immediate path is /helpers/html
		$filePath = $this->file->getFilePath();
		$filePath = str_replace('\\', '/', $filePath);
		$pathBits = explode('/', $filePath);

		if (implode('/', array_slice($pathBits, -3, 2)) !== 'helpers/html')
		{
			return null;
		}

		/**
		 * Change abstract classes to non-abstract
		 */
		if ($node instanceof Class_)
		{
			return $this->refactorClass($node);
		}

		/**
		 * Change static methods to non-static and refactor local static calls to non-static
		 *
		 * @see \Rector\RemovingStatic\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector
		 */
		if ($node instanceof ClassMethod)
		{
			return $this->refactorClassMethod($node);
		}

		if ($node instanceof StaticCall)
		{
			return $this->refactorStaticCall($node);
		}

		/**
		 * Add namespace and move the file
		 */
		return parent::refactor($node);
	}


	/**
	 * Process a Name or Identifier node but only if necessary!
	 *
	 * @param   Name|Identifier  $node  The node to possibly refactor
	 *
	 * @return  Identifier|Name|null  The refactored node; NULL if no refactoring was necessary / possible.
	 * @since   1.0.0
	 */
	protected function processNameOrIdentifier($node, bool $isNewFile = false): ?Node
	{
		// no name → skip
		if ($node->toString() === '')
		{
			return null;
		}

		// The class name must begin with a form of "JHtml".
		$supported = [
			'JHtml*',
			'Jhtml*',
			'JHTML*',
			'jhtml*',
			'jHtml*',
			'jHTML*',
		];

		if (!$this->isNames($node, $supported))
		{
			return null;
		}

		foreach ($this->legacyPrefixesToNamespaces as $legacyPrefixToNamespace)
		{
			$prefix          = substr($this->getName($node), 0, 5);
			$excludedClasses = $legacyPrefixToNamespace->getExcludedClasses();

			if ($excludedClasses !== [] && $this->isNames($node, $excludedClasses))
			{
				return null;
			}

			if ($node instanceof Name)
			{
				return $this->processName($node, $prefix, $legacyPrefixToNamespace->getNewNamespace(), $isNewFile);
			}

			return $this->processIdentifier($node, $prefix, $legacyPrefixToNamespace->getNewNamespace(), $isNewFile);
		}

		return null;
	}

	/**
	 * Convert an abstract class to non-abstract
	 *
	 * @param   Class_  $node
	 *
	 * @return  Class_|null
	 */
	private function refactorClass(Class_ $node)
	{
		if (!$node->isAbstract())
		{
			return null;
		}

		$node->flags = $node->flags & ~Class_::MODIFIER_ABSTRACT;

		return $node;
	}

	/**
	 * Convert a static class method to non-static
	 *
	 * @param   ClassMethod  $classMethod
	 *
	 * @return  ClassMethod|null
	 * @since   1.0.0
	 * @see     \Rector\RemovingStatic\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector
	 */
	private function refactorClassMethod(ClassMethod $classMethod) : ?ClassMethod
	{
		if (!$classMethod->isStatic())
		{
			return null;
		}

		$dirty = false;

		if (($classMethod->flags & Class_::VISIBILITY_MODIFIER_MASK) === 0)
		{
			$this->visibilityManipulator->makePublic($classMethod);

			$dirty = true;
		}

		$classReflection = $this->reflectionResolver->resolveClassReflection($classMethod);

		if (!$classReflection instanceof ClassReflection)
		{
			return $dirty ? $classMethod : null;
		}

		if ($this->classMethodVisibilityGuard->isClassMethodVisibilityGuardedByParent($classMethod, $classReflection))
		{
			return $dirty ? $classMethod : null;
		}

		// Change static calls to non-static ones, but only if in non-static method!!!
		$this->visibilityManipulator->makeNonStatic($classMethod);

		return $classMethod;
	}

	/**
	 * Refactor a static method call to non-static, within the same class only
	 *
	 * @param   StaticCall  $staticCall
	 *
	 * @return  MethodCall|null
	 * @since   1.0.0
	 * @see     \Rector\RemovingStatic\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector
	 */
	private function refactorStaticCall(StaticCall $staticCall): ?MethodCall
	{
		$classLike = $this->betterNodeFinder->findParentType($staticCall, ClassLike::class);

		if (!$classLike instanceof ClassLike)
		{
			return null;
		}

		/** @var ClassMethod[] $classMethods */
		$classMethods = $this->betterNodeFinder->findInstanceOf($classLike, ClassMethod::class);

		foreach ($classMethods as $classMethod)
		{
			if (!$this->isClassMethodMatchingStaticCall($classMethod, $staticCall))
			{
				continue;
			}

			if ($this->isInStaticClassMethod($staticCall))
			{
				continue;
			}

			$thisVariable = new Variable('this');

			return new MethodCall($thisVariable, $staticCall->name, $staticCall->args);
		}

		return null;
	}

	/**
	 * Are we inside a static class method?
	 *
	 * @param   StaticCall  $staticCall
	 *
	 * @return bool
	 * @since   1.0.0
	 * @see     \Rector\RemovingStatic\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector
	 */
	private function isInStaticClassMethod(StaticCall $staticCall): bool
	{
		$locationClassMethod = $this->betterNodeFinder->findParentType($staticCall, ClassMethod::class);

		if (!$locationClassMethod instanceof ClassMethod)
		{
			return \false;
		}

		return $locationClassMethod->isStatic();
	}

	/**
	 * @param   ClassMethod  $classMethod
	 * @param   StaticCall   $staticCall
	 *
	 * @return bool
	 * @since   1.0.0
	 * @see     \Rector\RemovingStatic\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector
	 */
	private function isClassMethodMatchingStaticCall(ClassMethod $classMethod, StaticCall $staticCall): bool
	{
		$classLike = $this->betterNodeFinder->findParentType($classMethod, ClassLike::class);

		if (!$classLike instanceof ClassLike)
		{
			return \false;
		}

		$className  = (string) $this->nodeNameResolver->getName($classLike);
		$objectType = new ObjectType($className);
		$callerType = $this->nodeTypeResolver->getType($staticCall->class);

		return $objectType->equals($callerType);
	}
}
