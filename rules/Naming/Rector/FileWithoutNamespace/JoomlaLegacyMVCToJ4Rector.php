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
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaRemovedAndAddedFilesCollector;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Node\CustomNode\FileWithoutNamespace;
use Rector\Core\Rector\AbstractRector;
use Rector\Naming\Config\JoomlaLegacyPrefixToNamespace;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Webmozart\Assert\Assert;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * A Rector rule to namespace legacy Joomla 3 MVC classes into Joomla 4+ MVC namespaced classes
 *
 * @since  1.0.0
 * @see    \Rector\Tests\Naming\Rector\FileWithoutNamespace\JoomlaLegacyMVCToJ4Rector\JoomlaLegacyToNamespacedRectorTest
 */
class JoomlaLegacyMVCToJ4Rector extends AbstractRector implements ConfigurableRectorInterface
{
	use JoomlaNamespaceHandlingTrait;

	/**
	 * The configuration mapping legacy class prefixes to Joomla 4 namespaces.
	 *
	 * @since 1.0.0
	 * @var   JoomlaLegacyPrefixToNamespace[]
	 */
	protected $legacyPrefixesToNamespaces = [];

	/**
	 * The new namespace being applied to the current class file being refactored.
	 *
	 * @since 1.0.0
	 * @var   null|string
	 * @readonly
	 */
	protected $newNamespace = null;

	/**
	 * Rector utility object which collects the filename changes
	 *
	 * @since 1.0.0
	 * @var   JoomlaRemovedAndAddedFilesCollector
	 * @readonly
	 */
	protected $removedAndAddedFilesCollector;

	/**
	 * Stores the renamed class map for a second pass.
	 *
	 * @var   RenamedClassHandlerService
	 * @since 1.0.0
	 */
	private $renamedClassHandlerService;

	/**
	 * Public constructor.
	 *
	 * Rector (well, Symfony) automatically pushes the dependencies we ask for through its DI container.
	 *
	 * @param   JoomlaRemovedAndAddedFilesCollector  $removedAndAddedFilesCollector
	 *
	 * @since   1.0.0
	 */
	public function __construct(
		JoomlaRemovedAndAddedFilesCollector $removedAndAddedFilesCollector,
		RenamedClassHandlerService    $renamedClassHandlerService
	)
	{
		$this->removedAndAddedFilesCollector = $removedAndAddedFilesCollector;
		$this->renamedClassHandlerService    = $renamedClassHandlerService;
	}

	/**
	 * Configuration handler. Called internally by Rector.
	 *
	 * @param   JoomlaLegacyPrefixToNamespace[]  $configuration
	 *
	 * @since   1.0.0
	 */
	public function configure(array $configuration): void
	{
		Assert::allIsAOf($configuration, JoomlaLegacyPrefixToNamespace::class);
		$this->legacyPrefixesToNamespaces = $configuration;
	}

	/**
	 * Tell Rector which AST node types we can handle with this rule.
	 *
	 * @return  array<class-string<Node>>
	 * @since   1.0.0
	 */
	public function getNodeTypes(): array
	{
		return [
			FileWithoutNamespace::class, Namespace_::class,
		];
	}

	/**
	 * @return JoomlaRemovedAndAddedFilesCollector
	 */
	public function getRemovedAndAddedFilesCollector(): JoomlaRemovedAndAddedFilesCollector
	{
		return $this->removedAndAddedFilesCollector;
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
		return new RuleDefinition('Convert legacy Joomla 3 MVC class names into Joomla 4 namespaced ones.', [
			new CodeSample(
				<<<'CODE_SAMPLE'
/** @var FooModelBar $someModel */
$model = new FooModelBar;
CODE_SAMPLE
				, <<<'CODE_SAMPLE'
/** @var \Acme\Foo\BarModel $someModel */
$model = new BarModel;
CODE_SAMPLE
			),
		]);
	}

	/**
	 * Performs the refactoring on the supported nodes.
	 *
	 * @param   FileWithoutNamespace|Namespace_  $node
	 *
	 * @since   1.0.0
	 */
	public function refactor(Node $node): ?Node
	{
		$this->newNamespace = null;

		if ($node instanceof FileWithoutNamespace)
		{
			$changedStmts = $this->refactorStmts($node->stmts, true);

			if ($changedStmts === null)
			{
				return null;
			}

			$node->stmts = $changedStmts;

			// Add a new namespace?
			if ($this->newNamespace !== null)
			{
				return new Namespace_(new Name($this->newNamespace), $changedStmts);
			}
		}

		if ($node instanceof Namespace_)
		{
			return $this->refactorNamespace($node);
		}

		return null;
	}

	/**
	 * Processes an Identifier node
	 *
	 * @param   Identifier  $identifier          The node to process
	 * @param   string      $prefix              The legacy Joomla 3 prefix, e.g. Example
	 * @param   string      $newNamespacePrefix  The Joomla 4 common namespace prefix e.g. \Acme\Example
	 * @param   bool        $isNewFile           Is this a file without a namespace already defined?
	 *
	 * @return  Identifier|null  The refactored identified; null if no refactoring is necessary / possible
	 * @throws  ShouldNotHappenException  A file had two classes in it yielding different namespaces. Don't do that!
	 * @since   1.0.0
	 */
	protected function processIdentifier(Identifier $identifier, string $prefix, string $newNamespacePrefix, bool $isNewFile = false): ?Identifier
	{
		$parentNode = $identifier->getAttribute(AttributeKey::PARENT_NODE);

		if (!$parentNode instanceof Class_)
		{
			return null;
		}

		$name = $this->getName($identifier);

		if ($name === null)
		{
			return null;
		}

		$newNamespace    = '';
		$lastNewNamePart = $name;
		$fqn             = $this->legacyClassNameToNamespaced($name, $prefix, $newNamespacePrefix, $isNewFile);

		if ($fqn === $name)
		{
			return $identifier;
		}

		$this->renamedClassHandlerService->addEntry($name, $fqn, $newNamespacePrefix);

		$bits = explode('\\', $fqn);

		if (count($bits) > 1)
		{
			$lastNewNamePart = array_pop($bits);
			$newNamespace    = implode('\\', $bits);
		}

		if ($this->newNamespace !== null && $this->newNamespace !== $newNamespace)
		{
			throw new ShouldNotHappenException('There cannot be 2 different namespaces in one file');
		}

		$this->newNamespace = $newNamespace;
		$identifier->name   = $lastNewNamePart;

		$this->moveFile($newNamespacePrefix, $fqn);

		return $identifier;
	}

	/**
	 * Process a Name node
	 *
	 * @param   Name    $name                The node to refactor
	 * @param   string  $prefix              The legacy Joomla 3 prefix, e.g. Example
	 * @param   string  $newNamespacePrefix  The Joomla 4 common namespace prefix e.g. \Acme\Example
	 * @param   bool    $isNewFile           Is this a file without a namespace already defined?
	 *
	 * @return  Name  The refactored Node. Original node if nothing was refactored.
	 * @since   1.0.0
	 */
	protected function processName(Name $name, string $prefix, string $newNamespace, bool $isNewFile = false): Name
	{
		// The class name
		$legacyClassName = $this->getName($name);

		$fqn = $this->legacyClassNameToNamespaced($legacyClassName, $prefix, $newNamespace, $isNewFile);

		if ($fqn === $legacyClassName)
		{
			return $name;
		}

		$name->parts = explode('\\', $fqn);

		return $name;
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

		foreach ($this->legacyPrefixesToNamespaces as $legacyPrefixToNamespace)
		{
			$prefix    = $legacyPrefixToNamespace->getNamespacePrefix();
			$supported = [
				$prefix . 'Controller*',
				$prefix . 'Model*',
				$prefix . 'View*',
				$prefix . 'Table*',
			];

			if (!$this->isNames($node, $supported))
			{
				continue;
			}

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
	 * Refactor a namespace node
	 *
	 * @param   Namespace_  $namespace  The node to possibly refactor
	 *
	 * @return  Namespace_|null  The refactored node; NULL if nothing is refactored
	 * @since   1.0.0
	 */
	protected function refactorNamespace(Namespace_ $namespace): ?Namespace_
	{
		$changedStmts = $this->refactorStmts($namespace->stmts);

		if ($changedStmts === null)
		{
			return null;
		}

		return $namespace;
	}

	/**
	 * Refactor an array of statement nodes
	 *
	 * @param   array  $stmts      The array of nodes to possibly refactor
	 * @param   bool   $isNewFile  Is this a file without a namespace?
	 *
	 * @return  array|null  The array of refactored statements. NULL if was nothing to refactor.
	 * @since   1.0.0
	 */
	protected function refactorStmts(array $stmts, bool $isNewFile = false): ?array
	{
		$hasChanged = \false;

		$this->traverseNodesWithCallable($stmts, function (Node $node) use (&$hasChanged, $isNewFile): ?Node {
			if (
				!$node instanceof Name
				&& !$node instanceof Identifier
				&& !$node instanceof Property
				&& !$node instanceof FunctionLike
			)
			{
				return null;
			}

			if (
				$node instanceof Name
				|| $node instanceof Identifier
			)
			{
				$changedNode = $this->processNameOrIdentifier($node, $isNewFile);

				if ($changedNode instanceof Node)
				{
					$hasChanged = \true;

					return $changedNode;
				}
			}

			return null;
		});

		if ($hasChanged)
		{
			return $stmts;
		}

		return null;
	}
}
