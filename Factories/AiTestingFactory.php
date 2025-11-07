<?php

namespace axenox\GenAI\Factories;

use axenox\GenAI\Exceptions\AiTestingMetricNotFoundError;
use axenox\GenAI\Interfaces\AiTestCriterionInterface;
use axenox\GenAI\Interfaces\AiTestMetricInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

class AiTestingFactory
{
    
    public static function createMetricFromUxon(WorkbenchInterface $workbench, UxonObject $uxon) : AiTestMetricInterface
    {
        if(!$uxon->hasProperty('type')){
            throw new AiTestingMetricNotFoundError('No metric available, so it could not be found');
        }
        $type = $uxon->getProperty('type');
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.GenAI.AI_TEST_METRIC_PROTOTYPE');
        $className = StringDataType::convertCaseUnderscoreToPascal($type) . 'TestMetric.php';
        $ds->getFilters()->addConditionFromString('FILENAME', $className, ComparatorDataType::EQUALS);
        $ds->getColumns()->addMultiple([
            'PATHNAME_ABSOLUTE',
            'FILENAME'
        ]);
        $ds->dataRead();
        if($ds->isEmpty()){
            throw new AiTestingMetricNotFoundError("Test Metric '$className' not found");
        }

        $row = $ds->getRow(0);

        $path = $row['PATHNAME_ABSOLUTE'];
        $class = PhpFilePathDataType::findClassInFile($path, 1000);
        if (! $uxon->hasProperty('name')) {
            $uxon->setProperty('name', $type);
        }


        $metric = new $class($workbench, $uxon);
        
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