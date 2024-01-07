<?php
/**
 * Joomla 3 Component Upgrade Rectors
 *
 * @copyright  2022 Nicholas K. Dionysopoulos
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Rector\Naming\Rector;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use Rector\Core\PhpParser\Node\CustomNode\FileWithoutNamespace;
use Rector\Core\Rector\AbstractRector;
use Rector\Config\RectorConfig;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaNamespaceHandlingTrait;
use Rector\Naming\Rector\FileWithoutNamespace\RenamedClassHandlerService;
use Rector\Renaming\NodeManipulator\ClassRenamer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use \PHPStan\Analyser\Scope;

class JoomlaPostRefactoringClassRenameRector extends AbstractRector
{
	use JoomlaNamespaceHandlingTrait;

	/**
	 * @readonly
	 * @var \Rector\Renaming\NodeManipulator\ClassRenamer
	 */
	private $classRenamer;

	/**
	 * @readonly
	 * @var \Rector\Core\Configuration\RectorConfigProvider
	 */
	private $rectorConfigProvider;

	/**
	 * @readonly
	 * @var RenamedClassHandlerService
	 */
	private $renamedClassHandlerService;

	public function __construct(RenamedClassHandlerService $renamedClassHandlerService, ClassRenamer $classRenamer)
	{
		$this->renamedClassHandlerService = $renamedClassHandlerService;
		$this->classRenamer               = $classRenamer;
	}

	/**
	 * @return array<class-string<Node>>
	 */
	public function getNodeTypes(): array
	{
		return [
			Name::class, Property::class, FunctionLike::class, Expression::class, ClassLike::class, Namespace_::class,
			FileWithoutNamespace::class, Use_::class,
		];
	}

	public function getRuleDefinition(): RuleDefinition
	{
		return new RuleDefinition('Replaces defined classes by new ones.', [
			new CodeSample(
				<<<'CODE_SAMPLE'
class ExampleModelFoobar extends \Joomla\CMS\MVC\Model\BaseModel
{
	/**
	 * @return ExampleTableFoobar
	 */
	public function doSomething(): ExampleTableFoobar
	{
		return $this->getTable();
	}
}
CODE_SAMPLE
				, <<<'CODE_SAMPLE'
namespace \Acme\Example\Administrator\Model;

use \Acme\Example\Administrator\Table\FoobarTable;

class FoobarModel extends \Joomla\CMS\MVC\Model\BaseModel
{
	/**
	 * @return FoobarTable
	 */
	public function doSomething(): FoobarTable
	{
		return $this->getTable();
	}
}
CODE_SAMPLE
			),
		]);
	}

	/**
	 * @param   FunctionLike|Name|ClassLike|Expression|Namespace_|Property|FileWithoutNamespace|Use_  $node
	 */
	public function refactor(Node $node): ?Node
	{
		$applicationSide = strtolower($this->getApplicationSide());
		$applicationSide = ($applicationSide === 'administrator') ? 'admin' : $applicationSide;
		$oldToNewClasses = $this->renamedClassHandlerService->getOldToNewMap($applicationSide);

		if ($oldToNewClasses === [])
		{
			return null;
		}

		if (!$node instanceof Use_)
		{
return null;
			//return $this->classRenamer->renameNode($node, $oldToNewClasses,null);
		}

		//if (!$this->rectorConfigProvider->shouldImportNames())
		//{
		////	return null;
		//}

		return $this->processCleanUpUse($node, $oldToNewClasses);
	}

	/**
	 * @param   array<string, string>  $oldToNewClasses
	 */
	private function processCleanUpUse(Use_ $use, array $oldToNewClasses): ?Use_
	{
		foreach ($use->uses as $useUse)
		{
			if (!$useUse->alias instanceof Identifier && isset($oldToNewClasses[$useUse->name->toString()]))
			{
				$this->removeNode($use);

				return $use;
			}
		}

		return null;
	}
}
