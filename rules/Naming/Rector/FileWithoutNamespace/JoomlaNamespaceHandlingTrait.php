<?php
/**
 * Joomla 3 Component Upgrade Rectors
 *
 * @copyright  2022 Nicholas K. Dionysopoulos
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Rector\Naming\Rector\FileWithoutNamespace;

/**
 * Common code for Joomla-specific Rector rules
 *
 * @since  1.0.0
 */
trait JoomlaNamespaceHandlingTrait
{
	/**
	 * Try to guess the absolute filesystem path where the current side of the component is stored.
	 *
	 * @return  string|null  Null if we fail to divine this information.
	 * @since   1.0.0
	 */
	protected function divineExtensionRootFolder(): ?string
	{
		$path     = str_replace('\\', '/', $this->file->getFilePath());
		$pathBits = explode('/', $path);

		while (!empty($pathBits))
		{
			$lastPart = array_pop($pathBits);

			if ($lastPart === null)
			{
				return null;
			}

			$isComponent = substr($lastPart, 0, 4) === 'com_';

			if ($isComponent || in_array($lastPart, JoomlaConstants::ACCEPTABLE_CONTAINMENT_FOLDERS))
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
	protected function getApplicationSide(): string
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
		if (in_array($temp, JoomlaConstants::ACCEPTABLE_CONTAINMENT_FOLDERS) || substr($temp, 0, 4) === 'com_')
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

			if (!in_array($lastFolder, JoomlaConstants::ACCEPTABLE_CONTAINMENT_FOLDERS))
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
	 * @param   string  $componentPrefix  The common prefix of the legacy Joomla 3 classes, e.g. Example for
	 *                                    com_example
	 * @param   string  $newNamespace     The common namespace prefix for the Joomla 4 component
	 * @param   bool    $isNewFile        Is this a file without a namespace already defined?
	 *
	 * @return  string  The FQN of the namespaced Joomla 4 class e.g.
	 *                  \Acme\Example\Administrator\Controller\ExampleController
	 * @since   1.0.0
	 */
	protected function legacyClassNameToNamespaced(string $legacyClassName, string $componentPrefix, string $newNamespace, bool $isNewFile = false): string
	{
		$applicationSide = $this->getApplicationSide();

		// Controller, Model and Table are pretty straightforward
		$legacySuffixes = ['Controller', 'Model', 'Table', 'Helper'];

		/**
		 * Special case: JHtml prefix
		 *
		 * HTML helper static classes become non-static HTML helper services, renamed and moved accordingly.
		 */
		if (strtoupper(substr($legacyClassName, 0, 5)) === 'JHTML')
		{
			$fqn = trim($newNamespace, '\\')
				. '\\' . $applicationSide
				. '\\Service\\Html\\'
				. ucfirst(strtolower(substr($legacyClassName, 5)));

			return $fqn;
		}
		/**
		 * Special case: JFormRule prefix
		 *
		 * Form rule classes are renamed and moved accordingly.
		 */
		elseif (strtoupper(substr($legacyClassName, 0, 9)) === 'JFORMRULE')
		{
			$fqn = trim($newNamespace, '\\')
				. '\\' . $applicationSide
				. '\\Rule\\'
				. ucfirst(strtolower(substr($legacyClassName, 9))) . 'Rule';

			return $fqn;
		}
		/**
		 * Special case: JFormField prefix
		 *
		 * Form field type classes are renamed and moved accordingly.
		 */
		elseif (strtoupper(substr($legacyClassName, 0, 10)) === 'JFORMFIELD')
		{
			$bareName = strtolower(substr($legacyClassName, 10));
			$bareName = str_replace('_', '\\', $bareName);
			$bareParts = explode('\\', $bareName);
			$bareParts = array_map('ucfirst', $bareParts);
			$bareName = implode('\\', $bareParts);
			$fqn      = trim($newNamespace, '\\')
				. '\\' . $applicationSide
				. '\\Field\\'
				. $bareName . 'Field';

			return $fqn;
		}

		foreach ($legacySuffixes as $legacySuffix)
		{
			$fullLegacyPrefix = $componentPrefix . $legacySuffix;

			if ($legacyClassName === $fullLegacyPrefix)
			{
				if (!in_array($legacySuffix, ['Controller', 'Helper']))
				{
					return $legacyClassName;
				}

				// If the file already has a namespace go away. We have already refactored it.
				if (!$isNewFile)
				{
					return $legacyClassName;
				}

				switch ($legacySuffix)
				{
					case 'Controller':
						$legacyClassName = $fullLegacyPrefix . 'Display';
						break;

					case 'Helper':
						$legacyClassName = $fullLegacyPrefix . $componentPrefix;
						break;
				}
			}
			/**
			 * Special handling for regular Helper classes.
			 *
			 * Naming pattern for com_example: ExampleSomethingHelper
			 */
			elseif ($legacySuffix === 'Helper' && substr($legacyClassName, -6) === 'Helper')
			{
				// Rewrite ExampleSomethingHelper to ExampleHelperSomething for our code below to work.
				$plain           = substr($legacyClassName, strlen($componentPrefix));
				$plain           = substr($plain, 0, -strlen($legacySuffix));
				$legacyClassName = $componentPrefix . $legacySuffix . $plain;
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

		/**
		 * Special handling for View classes
		 */
		$fullLegacyPrefix = $componentPrefix . 'View';

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
	protected function moveFile(string $newNamespacePrefix, string $fqn)
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
		mkdir(dirname($newPath),0777,true);
		copy($this->file->getFilePath(),$newPath);
		$myfile = fopen("logs.txt", "a") or die("Unable to open file!");
		$txt = "".$this->file->getFilePath().'='.$newPath;
		fwrite($myfile, "\n". $txt);
		fclose($myfile);
		//rename($this->file->getFilePath(),$newPath);
		//$this->getRemovedAndAddedFilesCollector()->addMovedFile($this->file, $newPath);

	}
}