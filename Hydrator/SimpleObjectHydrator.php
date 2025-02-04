<?php

namespace steevanb\DoctrineReadOnlyHydrator\Hydrator;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Internal\HydrationCompleteHandler;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class SimpleObjectHydrator extends ArrayHydrator
{
    const HYDRATOR_NAME = 'simpleObject';
    const READ_ONLY_PROPERTY = '__SIMPLE_OBJECT_HYDRATOR__READ_ONLY__';

    /** @var string */
    protected $rootClassName;

    protected function prepare()
    {
        parent::prepare();

        $this->rootClassName = null;
    }

    protected function cleanup()
    {
        parent::cleanup();

        $this->_uow->hydrationComplete();
    }

    /**
     * @return array
     */
    protected function hydrateAllData()
    {
        $arrayResult = parent::hydrateAllData();

        $readOnlyResult = [];
        if (is_array($arrayResult)) {
            foreach ($arrayResult as $data) {
                $readOnlyResult[] = $this->doHydrateRowData($this->getRootclassName(), $data);
            }
        }
        return $readOnlyResult;
    }

    /**
     * @return string
     */
    protected function getRootclassName()
    {
        // i don't understand when we can have more than one item in ArrayHydrator::$_rootAliases
        // so, i assume first one is the right one
        if ($this->rootClassName === null) {
            $rootAlias = key($this->getPrivatePropertyValue($this, '_rootAliases'));
            $this->rootClassName = $this->_rsm->aliasMap[$rootAlias];
        }

        return $this->rootClassName;
    }

    /**
     * @param string $className
     * @param array $data
     * @return object
     * @throws \Exception
     */
    protected function doHydrateRowData($className, array $data)
    {
        $classMetaData = $this->_em->getClassMetadata($className);
        $mappings = $classMetaData->getAssociationMappings();
        $entity = $this->createEntity($classMetaData, $data);
        $reflection = new \ReflectionObject($entity);

        $data = $this->dataGroupEmbended($classMetaData, $data);

        foreach ($mappings as $propertyName => $property) {

            if (!isset($property['joinColumns'])) {
                continue;
            }

            $dbFldName = $property['joinColumns'][0]['name'];

            if (!array_key_exists($dbFldName, $data)) {
                continue;
            }

            if ($dbFldName === $propertyName) {
                continue;
            }

            $fkColumn = current(
                $mappings[$propertyName]['sourceToTargetKeyColumns']
            );
            $data[$propertyName] = [
                $fkColumn  => $data[$dbFldName]
            ];
            unset($data[$dbFldName]);
        }

        foreach ($data as $name => $value) {
            if (isset($mappings[$name]) && is_array($value)) {
                switch ($mappings[$name]['type']) {
                    case ClassMetadata::ONE_TO_ONE:
                        $value = $this->hydrateOneToOne($mappings[$name], $value);
                        break;
                    case ClassMetadata::ONE_TO_MANY:
                        $value = $this->hydrateOneToMany($mappings[$name], $value);
                        break;
                    case ClassMetadata::MANY_TO_ONE:
                        $value = $this->hydrateManyToOne($mappings[$name], $value);
                        break;
                    case ClassMetadata::MANY_TO_MANY:
                        $value = $this->hydrateManyToMany($mappings[$name], $value);
                        break;
                    default:
                        throw new \Exception('Unknow mapping type "' . $mappings[$name]['type'] . '".');
                }
            }

            if(isset($classMetaData->embeddedClasses[$name])){
                $value = $this->hydrateEmbended($classMetaData, $name, $value);
            }

            if (
                $classMetaData->inheritanceType === ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE
               || $classMetaData->inheritanceType === ClassMetadata::INHERITANCE_TYPE_JOINED
            ) {
               try {
                   $property = $reflection->getProperty($name);
               } catch (\ReflectionException $e) {
                   continue;
               }
            } else {
                if($reflection->hasProperty($name)){
                    $property = $reflection->getProperty($name);
                }else{
                    $property = $this->getPrivateProperty($entity, $name);
                }
            }

            if ($property->isPublic()) {
                $entity->$name = $value;
            } else {
                $property->setAccessible(true);
                $property->setValue($entity, $value);
                $property->setAccessible(false);
            }
        }
        unset($mappings, $reflection, $classMetaData,$data, $property, $value);
        return $entity;
    }

    protected function dataGroupEmbended($classMetaData, $data){
        $result = [];
        foreach ($data as $name => $value){
            if(!$this->propertyIsEbbended($classMetaData, $name)) {
                $result[$name] = $value;
                continue;
            }
            $pointPosition = strpos( $name,".");

            $result[substr($name, 0, $pointPosition)][substr($name, $pointPosition+1)] = $value;
        }
        unset($data);
        return $result;
    }

    protected function propertyIsEbbended($classMetaData, $name){
        $pointPosition = strpos( $name,".");
        if($pointPosition !== false){
            $ebbendedName = substr($name, 0, $pointPosition);
            return !empty($classMetaData->embeddedClasses[$ebbendedName]);
        }
        return false;
    }

    protected function hydrateEmbended($classMetaData, $name, $value)
    {
        return $this->doHydrateRowData($classMetaData->embeddedClasses[$name]["class"], $value);
    }

    /**
     * @param ClassMetadata $classMetaData
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    protected function createEntity(ClassMetadata $classMetaData, array $data)
    {
        $className = $this->getEntityClassName($classMetaData, $data);
        $reflection = new \ReflectionClass($className);
        $entity = $reflection->newInstanceWithoutConstructor();

        $entity->{static::READ_ONLY_PROPERTY} = true;

        $this->deferPostLoadInvoking($classMetaData, $entity);

        unset($reflection, $data, $className);
        return $entity;
    }

    /**
     * @param ClassMetadata $classMetaData
     * @param object $entity
     * @return $this
     */
    protected function deferPostLoadInvoking(ClassMetadata $classMetaData, $entity)
    {
        // Do not notify hydration completed

        // /** @var HydrationCompleteHandler $handler */
        // $handler = $this->getPrivatePropertyValue($this->_uow, 'hydrationCompleteHandler');
        // $handler->deferPostLoadInvoking($classMetaData, $entity);

        return $this;
    }

    /**
     * @param ClassMetadata $classMetaData
     * @param array $data
     * @return string
     * @throws \Exception
     */
    protected function getEntityClassName(ClassMetadata $classMetaData, array $data)
    {
        switch ($classMetaData->inheritanceType) {
            case ClassMetadata::INHERITANCE_TYPE_NONE:
                $return = $classMetaData->name;
                break;
            case ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE:
            case ClassMetadata::INHERITANCE_TYPE_JOINED:
                if (isset($data[$classMetaData->discriminatorColumn['name']]) === false) {
                    $exception = 'Discriminator column "' . $classMetaData->discriminatorColumn['name'] . '" ';
                    $exception .= 'for "' . $classMetaData->name . '" does not exists in $data.';
                    throw new \Exception($exception);
                }
                $discriminator = $data[$classMetaData->discriminatorColumn['name']];
                $return = $classMetaData->discriminatorMap[$discriminator];
                break;
            default:
                throw new \Exception('Unknow inheritance type "' . $classMetaData->inheritanceType . '".');
        }

        return $return;
    }

    /**
     * @param array $mapping
     * @param array $data
     * @return ArrayCollection
     */
    protected function hydrateOneToOne(array $mapping, $data)
    {
        return $this->doHydrateRowData($mapping['targetEntity'], $data);
    }

    /**
     * @param array $mapping
     * @param array $data
     * @return ArrayCollection
     */
    protected function hydrateOneToMany(array $mapping, $data)
    {
        $entities = [];
        foreach ($data as $key => $linkedData) {
            $entities[$key] = $this->doHydrateRowData($mapping['targetEntity'], $linkedData);
        }
        unset($mapping, $data);
        return new ArrayCollection($entities);
    }

    /**
     * @param array $mapping
     * @param array $data
     * @return ArrayCollection
     */
    protected function hydrateManyToOne(array $mapping, $data)
    {
        return $this->doHydrateRowData($mapping['targetEntity'], $data);
    }

    /**
     * @param array $mapping
     * @param array $data
     * @return ArrayCollection
     */
    protected function hydrateManyToMany(array $mapping, $data)
    {
        $entities = [];
        foreach ($data as $key => $linkedData) {
            $entities[$key] = $this->doHydrateRowData($mapping['targetEntity'], $linkedData);
        }
        unset($mapping, $data);
        return new ArrayCollection($entities);
    }

    /**
     * @param object $object
     * @param string $property
     * @return mixed
     * @throws \Exception
     */
    protected function getPrivatePropertyValue($object, $property)
    {
        $classNames = array_merge([get_class($object)], array_values(class_parents(get_class($object))));
        $classNameIndex = 0;
        do {
            try {
                $reflection = new \ReflectionProperty($classNames[$classNameIndex], $property);
                $continue = false;
            } catch (\ReflectionException $e) {
                $classNameIndex++;
                $continue = true;
            }
        } while ($continue);

        if (isset($reflection) === false || $reflection instanceof \ReflectionProperty === false) {
            throw new \Exception(get_class($object) . '::$' . $property . ' does not exists.');
        }
        unset($classNames);

        $accessible = $reflection->isPublic();
        $reflection->setAccessible(true);
        $value = $reflection->getValue($object);
        $reflection->setAccessible($accessible === false);

        unset($accessible, $reflection);
        return $value;
    }

    /**
     * @param object $object
     * @param string $property
     * @return mixed
     * @throws \Exception
     */
    protected function getPrivateProperty($object, $property)
    {
        $classNames = array_merge([get_class($object)], array_values(class_parents(get_class($object))));
        $classNameIndex = 0;
        do {
            try {
                $reflection = new \ReflectionProperty($classNames[$classNameIndex], $property);
                $continue = false;
            } catch (\ReflectionException $e) {
                $classNameIndex++;
                $continue = true;
            }
        } while ($continue && $classNameIndex < count($classNames));

        if (isset($reflection) === false || $reflection instanceof \ReflectionProperty === false) {
            throw new \Exception(get_class($object) . '::$' . $property . ' does not exists.');
        }

        unset($classNames);

        return $reflection;
    }
}
