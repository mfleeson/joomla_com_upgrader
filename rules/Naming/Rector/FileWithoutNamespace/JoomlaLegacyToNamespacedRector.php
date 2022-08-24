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
use Rector\Core\Application\FileSystem\RemovedAndAddedFilesCollector;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Node\CustomNode\FileWithoutNamespace;
use Rector\Core\Rector\AbstractRector;
use Rector\Naming\Config\JoomlaLegacyPrefixToNamespace;
use Rector\NodeTypeResolver\Node\AttributeKey;
use RectorPrefix202208\Webmozart\Assert\Assert;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * A Rector rule to namespace legacy Joomla 3 MVC classes into Joomla 4+ MVC namespaced classes
 *
 * @since  1.0.0
 * @see    \Rector\Tests\Naming\Rector\FileWithoutNamespace\JoomlaLegacyToNamespacedRector\JoomlaLegacyToNamespacedRectorTest
 */
final class JoomlaLegacyToNamespacedRector extends AbstractRector implements ConfigurableRectorInterface
{
	/**
	 * The acceptable folder names where component files can be placed in.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	private const ACCEPTABLE_CONTAINMENT_FOLDERS = ['admin', 'administrator', 'backend', 'site', 'frontend', 'api'];

	/**
	 * The configuration mapping legacy class prefixes to Joomla 4 namespaces.
	 *
	 * @since 1.0.0
	 * @var   JoomlaLegacyPrefixToNamespace[]
	 */
	private $legacyPrefixesToNamespaces = [];

	/**
	 * The new namespace being applied to the current class file being refactored.
	 *
	 * @since 1.0.0
	 * @var   null|string
	 * @readonly
	 */
	private $newNamespace = null;

	/**
	 * Rector utility object which collects the filename changes
	 *
	 * @since 1.0.0
	 * @var   RemovedAndAddedFilesCollector
	 * @readonly
	 */
	private $removedAndAddedFilesCollector;

	/**
	 * Public constructor.
	 *
	 * Rector (well, Symfony) automatically pushes the dependencies we ask for through its DI container.
	 *
	 * @param   RemovedAndAddedFilesCollector  $removedAndAddedFilesCollector
	 *
	 * @since   1.0.0
	 */
	public function __construct(
		RemovedAndAddedFilesCollector $removedAndAddedFilesCollector
	)
	{
		$this->removedAndAddedFilesCollector = $removedAndAddedFilesCollector;
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
	 * Try to guess the absolute filesystem path where the current side of the component is stored.
	 *
	 * @return  string|null  Null if we fail to divine this information.
	 * @since   1.0.0
	 */
	private function divineExtensionRootFolder(): ?string
	{
		$path     = str_replace('\\', '/', $this->file->getFilePath());
		$pathBits = explode('/', $path);

		for ($i = 0; $i < 3; $i++)
		{
			$lastPart = array_pop($pathBits);

			if ($lastPart === null)
			{
				return null;
			}

			$isComponent = substr($lastPart, 0, 4) === 'com_';

			if ($isComponent || in_array($lastPart, self::ACCEPTABLE_CONTAINMENT_FOLDERS))
			{
				$pathBits[] = $lastPart;

				break;
			}
		}

		if (empty($pathBits))
		{
			return null;
		}

		return implode('/', $pathBits);
	}

	/**
	 * Figure out which application side (admin, side or api) this file corresponds to.
	 *
	 * @return  string  One of 'Administrator', 'Site', 'Api'
	 * @since   1.0.0
	 */
	private function getApplicationSide(): string
	{
		/**
		 * I need to find the parent folder of my file to see if it's one of admin, administrator, backend, site,
		 * frontend, api and decide which namespace suffix to add.
		 */
		// The full path to the current file, normalised as a UNIX path
		$fullPath = str_replace('\\', '/', $this->file->getFilePath());
		// Explode the path to an array
		$pathBits = explode('/', $fullPath);
		// This is the filename
		array_pop($pathBits);
		// Remove the immediate folder we are in, I can infer it from the classname, duh
		$temp = array_pop($pathBits);
		// But, wait! What if it's the legacy display controller?! In this case I need to put that last folder back!
		if (in_array($temp, self::ACCEPTABLE_CONTAINMENT_FOLDERS) || substr($temp, 0, 4) === 'com_')
		{
			$pathBits[] = $temp;
		}
		$isTmpl = $temp === 'tmpl';
		// Get the parent folder
		$parentFolder = array_pop($pathBits);

		// If the parent folder starts with com_ I will get its grandparent instead
		if (substr($parentFolder, 0, 4) === 'com_')
		{
			$parentFolder = array_pop($pathBits);
			$parentFolder = array_pop($pathBits);
		}

		switch (strtolower(trim($parentFolder ?: '')))
		{
			case 'admin':
			case 'administrator':
			case 'backend':
				return 'Administrator';

			case 'site':
			case 'frontend':
				return 'Site';

			case 'api':
				return 'Api';
		}

		// I have no idea where I am. Okay, let's start going back until I find something that makes sense.
		$pathBits = explode('/', $fullPath);

		while (!empty($pathBits))
		{
			$lastFolder = array_pop($pathBits);

			if (!in_array($lastFolder, self::ACCEPTABLE_CONTAINMENT_FOLDERS))
			{
				continue;
			}

			switch (strtolower(trim($lastFolder ?: '')))
			{
				case 'admin':
				case 'administrator':
				case 'backend':
					return 'Administrator';

				case 'site':
				case 'frontend':
					return 'Site';

				case 'api':
					return 'Api';
			}
		}

		return 'Site';
	}

	/**
	 * Convert a legacy Joomla 3 class name to its Joomla 4 namespaced equivalent.
	 *
	 * @param   string  $legacyClassName  The legacy class name, e.g. ExampleControllerFoobar
	 * @param   string  $prefix           The common prefix of the legacy Joomla 3 classes, e.g. Example for
	 *                                    com_example
	 * @param   string  $newNamespace     The common namespace prefix for the Joomla 4 component
	 * @param   bool    $isNewFile        Is this a file without a namespace already defined?
	 *
	 * @return  string  The FQN of the namespaced Joomla 4 class e.g.
	 *                  \Acme\Example\Administrator\Controller\ExampleController
	 * @since   1.0.0
	 */
	private function legacyClassNameToNamespaced(string $legacyClassName, string $prefix, string $newNamespace, bool $isNewFile = false): string
	{
		$applicationSide = $this->getApplicationSide();

		// Controller, Model and Table are pretty straightforward
		$legacySuffixes = ['Controller', 'Model', 'Table'];

		foreach ($legacySuffixes as $legacySuffix)
		{
			$fullLegacyPrefix = $prefix . $legacySuffix;

			if ($legacyClassName === $fullLegacyPrefix)
			{
				if ($legacySuffix !== 'Controller')
				{
					return $legacyClassName;
				}

				// If the file already has a namespace go away. We have already refactored it.
				if (!$isNewFile)
				{
					return $legacyClassName;
				}

				$legacyClassName = $fullLegacyPrefix . 'Display';
			}

			if (strpos($legacyClassName, $fullLegacyPrefix) !== 0)
			{
				continue;
			}

			// Convert FooModelBar => BarModel
			$bareName = ucfirst(strtolower(substr($legacyClassName, strlen($fullLegacyPrefix)))) . $legacySuffix;

			$fqn = trim($newNamespace, '\\')
				. '\\' . $applicationSide
				. '\\' . $legacySuffix
				. '\\' . $bareName;

			return $fqn;
		}

		$fullLegacyPrefix = $prefix . 'View';

		if (strpos($legacyClassName, $fullLegacyPrefix) !== 0)
		{
			return $legacyClassName;
		}

		// The full path to the current file, normalised as a UNIX path
		$fullPath = str_replace('\\', '/', $this->file->getFilePath());
		// Explode the path to an array
		$pathBits = explode('/', $fullPath);
		// This is the filename
		$filename = array_pop($pathBits);
		/**
		 * Strip the 'view.' prefix and '.php' suffix from the filename, add 'View' to it. This changes a filename
		 * view.html.php into the HtmlView classname.
		 */
		$leafClassName = ucfirst(strtolower(str_replace(['view.', '.php'], ['', ''], $filename))) . 'View';

		// FooViewBar => Bar\HtmlView
		$bareName = ucfirst(strtolower(substr($legacyClassName, strlen($fullLegacyPrefix)))) . '\\' . $leafClassName;
		$fqn      = trim($newNamespace, '\\')
			. '\\' . $applicationSide
			. '\\View'
			. '\\' . $bareName;

		return $fqn;
	}

	/**
	 * Moves a (namespaced) file to its canonical PSR-4 folder
	 *
	 * @param   string  $newNamespacePrefix  The common namespace prefix for the component.
	 * @param   string  $fqn                 The FQN of the class whose file is being moved.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	private function moveFile(string $newNamespacePrefix, string $fqn)
	{
		// I also need to move the file
		$thisSideRoot = $this->divineExtensionRootFolder();

		if ($thisSideRoot === null)
		{
			return;
		}

		// Remove the common namespace prefix
		$newNamespacePrefix = trim($newNamespacePrefix, '\\');
		$fqn                = trim($fqn, '\\');

		if (strpos($fqn, $newNamespacePrefix) !== 0)
		{
			// Whatever happened is massively wrong. Give up.
			return;
		}

		/**
		 * Convert the namespace \Acme\Example\Administrator\Controller\ExampleController to
		 * /path/to/component/admin/src/Controller/ExampleController.php
		 *
		 * Logic:
		 * * Start with \Acme\Example\Administrator\Controller\ExampleController
		 * * Remove the common namespace, so it becomes Administrator\Controller\ExampleController
		 * * Remove the first part (Administrator). We're left with Controller\ExampleController.
		 * * Replace the backslashes with directory separators e.g. Controller/ExampleController
		 * * Make the path by combining
		 *    - The root of the component side e.g. /path/to/component/admin
		 *    - The literal 'src'
		 *    - The relative path from the previous step e.g. Controller/ExampleController
		 *    - The literal '.php'
		 * There we get /path/to/component/admin/src/Controller/ExampleController.php
		 */
		$relativeName = trim(substr($fqn, strlen($newNamespacePrefix)), '\\');
		$fqnParts     = explode('\\', $relativeName);
		array_shift($fqnParts);
		$newPath = $thisSideRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . implode(
				DIRECTORY_SEPARATOR,
				$fqnParts
			) . '.php';

		// Make sure we actually DO need to rename the file.
		if ($this->file->getFilePath() === $newPath)
		{
			// Okay, this is already in the correct PSR-4 folder. Bye-bye!
			return;
		}

		// Move the file
		$this->removedAndAddedFilesCollector->addMovedFile($this->file, $newPath);
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
	private function processIdentifier(Identifier $identifier, string $prefix, string $newNamespacePrefix, bool $isNewFile = false): ?Identifier
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
	private function processName(Name $name, string $prefix, string $newNamespace, bool $isNewFile = false): Name
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
	private function processNameOrIdentifier($node, bool $isNewFile = false): ?Node
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
	private function refactorNamespace(Namespace_ $namespace): ?Namespace_
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
	private function refactorStmts(array $stmts, bool $isNewFile = false): ?array
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
