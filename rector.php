<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Naming\Config\JoomlaLegacyPrefixToNamespace;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaHelpersToJ4Rector;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaLegacyMVCToJ4Rector;
use Rector\Naming\Rector\FileWithoutNamespace\RenamedClassHandlerService;
use Rector\Naming\Rector\JoomlaPostRefactoringClassRenameRector;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaHtmlHelpersRector;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaFormFieldsRector;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaFormRulesRector;
use Rector\Naming\Rector\FileWithoutNamespace\JoomlaRemovedAndAddedFilesCollector;

return static function (RectorConfig $rectorConfig): void {
$rectorConfig->disableParallel();

$rectorConfig->paths([
__DIR__ . '/admin',
__DIR__ . '/site',
__DIR__ . '/modules',
// Add any more directories or files your project may be using here
]);


$rectorConfig->skip([
// These are our auto-generated renamed class maps for the second pass
__DIR__ . '_classmap.php',
__DIR__ . '_classmap.json',
]);

// Required to autowire the custom services used by our Rector rules
$services = $rectorConfig
->services()
->defaults()
->autowire()
->autoconfigure();

// Register our custom services and configure them
$services->set(RenamedClassHandlerService::class)
->arg('$directory', __DIR__);

$services->set(JoomlaRemovedAndAddedFilesCollector::class)
	->arg('$directory', __DIR__);

// Basic refactorings
$rectorConfig->sets([
// Auto-refactor code to at least PHP 7.2 (minimum Joomla version)
LevelSetList::UP_TO_PHP_80,
// Replace legacy class names with the namespaced ones
__DIR__ . '/vendor/nikosdion/joomla_typehints/rector/joomla_4_0.php',
// Use early returns in if-blocks (code quality)
SetList::EARLY_RETURN,
]);

// Configure the namespace mappings
	$joomlaNamespaceMaps = [
		new JoomlaLegacyPrefixToNamespace('Helloworld', 'Acme\HelloWorld', []),
		new JoomlaLegacyPrefixToNamespace('HelloWorld', 'Acme\HelloWorld', []),
	];

// Auto-refactor the Joomla MVC classes
$rectorConfig->ruleWithConfiguration(JoomlaLegacyMVCToJ4Rector::class, $joomlaNamespaceMaps);
$rectorConfig->ruleWithConfiguration(JoomlaHelpersToJ4Rector::class, $joomlaNamespaceMaps);
$rectorConfig->ruleWithConfiguration(JoomlaHtmlHelpersRector::class, $joomlaNamespaceMaps);
$rectorConfig->ruleWithConfiguration(JoomlaFormFieldsRector::class, $joomlaNamespaceMaps);
$rectorConfig->ruleWithConfiguration(JoomlaFormRulesRector::class, $joomlaNamespaceMaps);
// Dual purpose. 1st pass: collect renamed classes. 2nd pass: apply the renaming to type hints.
$rectorConfig->rule(JoomlaPostRefactoringClassRenameRector::class);

// Replace Fully Qualified Names (FQN) of classes with `use` imports at the top of the file.
$rectorConfig->importNames();
// Do NOT import short class names such as `DateTime`
$rectorConfig->importShortClasses(false);
};