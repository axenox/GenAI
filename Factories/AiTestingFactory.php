<?php

namespace axenox\GenAI\Factories;

use axenox\GenAI\Common\Selectors\AiMetricSelector;
use axenox\GenAI\Exceptions\AiTestingMetricNotFoundError;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use axenox\GenAI\Interfaces\Selectors\AiMetricSelectorInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\AbstractStaticFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class AiTestingFactory extends AbstractStaticFactory
{
    public static function createMetricFromSelector(AiMetricSelectorInterface $selector) : AiTestMetricInterface
    {
        switch (true) {
            case $selector->isClassname():
                $class = $selector->toString();
                break;
            case $selector->isFilepath():
                $class = PhpFilePathDataType::findClassInFile($selector->toString());
                break;
            // Alias - e.g. axenox.GenAI.TextMatch
            default:
                // E.g. axenox.GenAI
                $appSelector = $selector->getAppSelector();
                $app = $selector->getWorkbench()->getApp($appSelector);
                $appPath = $app->getDirectoryAbsolutePath();
                $metricsPath = $appPath . DIRECTORY_SEPARATOR . 'AI' . DIRECTORY_SEPARATOR . 'Metrics';
                // E.g. TextMatch
                $metricAlias = StringDataType::substringAfter($appSelector->toString() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER);
                $filename = $metricsPath . DIRECTORY_SEPARATOR . $metricAlias . DIRECTORY_SEPARATOR . 'TestMetric.php';
                $class = PhpFilePathDataType::findClassInFile($filename);
        }
        $metric = new $class($selector->getWorkbench());
        return $metric;
    }
    
    public static function createMetricFromUxon(WorkbenchInterface $workbench, UxonObject $uxon) : AiTestMetricInterface
    {
        if(! $uxon->hasProperty('type')){
            throw new AiTestingMetricNotFoundError('No metric available, so it could not be found');
        }
        $type = $uxon->getProperty('type');
        $selector = new AiMetricSelector($workbench, $type);
        $metric = self::createMetricFromSelector($selector);
        $metric->importUxonObject($uxon);
        return $metric;
    }
    
    public static function createCriterionFromPathRel(WorkbenchInterface $workbench, string $prototypePathRel, UxonObject $uxon) : AiTestCriterionInterface
    {
        $pathAbs = $workbench->filemanager()->getPathToVendorFolder()
            . DIRECTORY_SEPARATOR . $prototypePathRel;
        $class = PhpFilePathDataType::findClassInFile($pathAbs);
        $criterion = new $class($workbench, $uxon);
        return $criterion;
    }

}