<?php
namespace ShipCore\DataObject;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use PhpDocReader\PhpDocReader;

abstract class DataObject
{
    /**
     *
     * @var AnnotationReader
     */
    private static $annotationReader;
    
    /**
     *
     * @var PhpDocReader
     */
    private static $docReader;

    /**
     *
     * @var \ReflectionClass
     */
    private $reflectionClass;
    
    public function __construct($data)
    {
        if (!self::$annotationReader) {
            self::$annotationReader =  new AnnotationReader();
            AnnotationRegistry::registerLoader('class_exists');
        }

        if (!self::$docReader) {
            self::$docReader =  new PhpDocReader();
        }

        $this->reflectionClass = new \ReflectionClass(static::class);
                
        /* @var $property \ReflectionProperty */
        foreach ($this->reflectionClass->getProperties() as $property) {
            $propertyName = $property->getName();
            if (isset($data[$propertyName])) {
                $this->setData($propertyName, $data[$propertyName]);
            } elseif ($this->isRequired($property)) {
                throw new \ShipCore\DataObject\Exception\MissingPropertyException(
                    static::class,
                    $propertyName
                );
            }
        }
    }
    
    private function isRequired(\ReflectionProperty $property)
    {
        return self::$annotationReader->getPropertyAnnotation($property, \ShipCore\DataObject\Annotation\Required::class);
    }
    
    private function isAccessible(\ReflectionProperty $property)
    {
        return self::$annotationReader->getPropertyAnnotation($property, \ShipCore\DataObject\Annotation\Accessible::class);
    }
    
    /**
     * Returns array type with stripped []
     * @param string $type
     * @return string
     */
    private function getArrayItemsType($type)
    {
        return substr($type, 0, strlen($type) - 2);
    }
    
    /**
     * Returns raw string type format from the var annotation
     * @param \ReflectionProperty $property
     * @return string
     */
    private function getRawType(\ReflectionProperty $property)
    {
        $matches = [];
        if (preg_match('/@var\s+([^\s]+)/', $property->getDocComment(), $matches)) {
            list(, $rawType) = $matches;
        } else {
            $rawType = 'mixed';
        }
        return $rawType;
    }
    
    /**
     *
     * @param string $rawType
     * @return bool
     */
    private function isArrayType($rawType)
    {
        return substr($rawType, -2) == "[]";
    }
    
    /**
     *
     * @param \ReflectionProperty $property
     * @return string
     * @throws \ShipCore\DataObject\Exception\UnkownTypeException
     */
    private function getType(\ReflectionProperty $property)
    {
        $propertyClass = self::$docReader->getPropertyClass($property);
        return $propertyClass ? $propertyClass : $this->getRawType($property);
    }
    
    /**
     *
     * @param \ReflectionProperty $property
     * @param string $type
     * @param mixed $value
     */
    private function parseArrayType(\ReflectionProperty $property, $type, $value)
    {
        if (!is_array($value)) {
            throw new \ShipCore\DataObject\Exception\InvalidTypeException(
                    static::class,
                    $property->getName(),
                    $this->getType($property)
            );
        }
        $itemType = $this->getArrayItemsType($type);
        $items = [];
        foreach ($value as $item) {
            if (
                (class_exists($itemType) || interface_exists($itemType))
                && is_array($item)
                && is_subclass_of($itemType, \ShipCore\DataObject\DataObject::class)
            ) {
                $item = new $itemType($item);
            }
            if (!$this->checkType($itemType, $item)) {
                throw new \ShipCore\DataObject\Exception\InvalidTypeException(
                    static::class,
                    $property->getName(),
                    $this->getType($property)
                );
            }
            $items[] = $item;
        }
        return $items;
    }
    
    private function checkType($type, $value)
    {
        switch ($type) {
            case 'boolean':
            case 'bool':
                return is_bool($value);
            case 'integer':
            case 'int':
            case 'long':
                return is_int($value);
            case 'string':
                return is_string($value);
            case 'double':
            case 'float':
                return is_numeric($value);
            case 'mixed':
                return true;
            default:
                return $value instanceof $type;
        }
    }
    
    private function setData($propertyName, $value)
    {
        $property = $this->reflectionClass->getProperty($propertyName);
        if ($property && $this->isAccessible($property)) {
            $propertyClass = self::$docReader->getPropertyClass($property);
            
            $rawType = $this->getRawType($property);
            if ($this->isArrayType($rawType)) {
                $valueArray = $this->parseArrayType($property, $rawType, $value);
                $property->setAccessible(true);
                $property->setValue($this, $valueArray);
            } else {
                if ($propertyClass && is_array($value) && is_subclass_of($propertyClass, \ShipCore\DataObject\DataObject::class)) {
                    $value = new $propertyClass($value);
                } elseif (!$this->checkType($this->getType($property), $value)) {
                    throw new \ShipCore\DataObject\Exception\InvalidTypeException(
                        static::class,
                        $propertyName,
                        $this->getType($property)
                    );
                }
                $property->setAccessible(true);
                $property->setValue($this, $value);
            }
        } else {
            throw new \ShipCore\DataObject\Exception\InvalidPropertyException(
                static::class,
                $propertyName
                );
        }
    }
    
    private function getProperty($propertyName)
    {
        $property = $this->reflectionClass->getProperty($propertyName);
        
        if ($property && $this->isAccessible($property)) {
            $property->setAccessible(true);
            return $property->getValue($this);
        } else {
            throw new \ShipCore\DataObject\Exception\InvalidPropertyException(
                static::class,
                $propertyName
                );
        }
    }
    
    private function setProperty($propertyName, $value)
    {
        $property = $this->reflectionClass->getProperty($propertyName);
        $propertyType = $this->getType($property);
        
        if ($property && $this->isAccessible($property)) {
            if ($this->checkType($propertyType, $value)) {
                $property->setAccessible(true);
                $property->setValue($this, $value);
            } else {
                throw new \ShipCore\DataObject\Exception\InvalidTypeException(
                    static::class,
                    $propertyName,
                    $propertyType
                );
            }
        } else {
            throw new \ShipCore\DataObject\Exception\InvalidPropertyException(
                static::class,
                $propertyName
                );
        }
    }
    
    /**
     * Set/Get attribute wrapper
     *
     * @param   string $method
     * @param   array $args
     * @return  mixed
     * @throws \Exception
     */
    public function __call($method, $args)
    {
        switch (substr($method, 0, 3)) {
            case 'get':
                $propertyName = lcfirst(substr($method, 3));
                return $this->getProperty($propertyName);
            case 'set':
                $propertyName = lcfirst(substr($method, 3));
                $value = isset($args[0]) ? $args[0] : null;
                return $this->setProperty($propertyName, $value);
        }
        throw new \Exception('Invalid method ' . static::class . '::' . $method);
    }
    
    public function toDataArray()
    {
        $data = [];
        /* @var $property \ReflectionProperty */
        foreach ($this->reflectionClass->getProperties() as $property) {
            if ($this->isAccessible($property)) {
                $property->setAccessible(true);
                $value = $property->getValue($this);
                if (isset($value)) {
                    $data[$property->getName()] =
                        is_subclass_of($value, \ShipCore\DataObject\DataObject::class) ? $value->toDataArray() : $value;
                }
            }
        }
        
        return $data;
    }
    
    public static function fromDataArray($data)
    {
        $className = static::class;
        return new $className($data);
    }
    
    public static function fromStdClass($data)
    {
        return self::fromDataArray(self::stdClassToArray($data));
    }
    
    private static function stdClassToArray($object)
    {
        if ($object instanceof \stdClass) {
            $data = [];
            foreach ((array)$object as $key => $value) {
                if ($value instanceof \stdClass) {
                    $value = self::stdClassToArray($value);
                } elseif (is_array($value)) {
                    foreach ($value as $childKey => $childValue) {
                        if ($childValue instanceof \stdClass) {
                            $childValue = self::stdClassToArray($childValue);
                            $value[$childKey] = $childValue;
                        }
                    }
                }
                $data[$key] = $value;
            }
            return $data;
        }
        
        return null;
    }
}
