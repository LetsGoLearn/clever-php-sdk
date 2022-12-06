<?php
/**
 * School
 *
 * PHP version 5
 *
 * @category Class
 * @package  Clever\Client
 * @author   Swagger Codegen team
 * @link     https://github.com/swagger-api/swagger-codegen
 */

/**
 * Clever API
 *
 * The Clever API
 *
 * OpenAPI spec version: 2.1.0
 * 
 * Generated by: https://github.com/swagger-api/swagger-codegen.git
 * Swagger Codegen version: 3.0.20
 */
/**
 * NOTE: This class is auto generated by the swagger code generator program.
 * https://github.com/swagger-api/swagger-codegen
 * Do not edit the class manually.
 */

namespace Clever\Client\Model;

use \ArrayAccess;
use \Clever\Client\ObjectSerializer;

/**
 * School Class Doc Comment
 *
 * @category Class
 * @package  Clever\Client
 * @author   Swagger Codegen team
 * @link     https://github.com/swagger-api/swagger-codegen
 */
class School implements ModelInterface, ArrayAccess
{
    const DISCRIMINATOR = null;

    /**
      * The original name of the model.
      *
      * @var string
      */
    protected static $swaggerModelName = 'School';

    /**
      * Array of property to type mappings. Used for (de)serialization
      *
      * @var string[]
      */
    protected static $swaggerTypes = [
        'created' => 'string',
'district' => 'string',
'ext' => 'object',
'high_grade' => 'string',
'id' => 'string',
'last_modified' => 'string',
'location' => '\Clever\Client\Model\Location',
'low_grade' => 'string',
'mdr_number' => 'string',
'name' => 'string',
'nces_id' => 'string',
'phone' => 'string',
'principal' => '\Clever\Client\Model\Principal',
'school_number' => 'string',
'sis_id' => 'string',
'state_id' => 'string'    ];

    /**
      * Array of property to format mappings. Used for (de)serialization
      *
      * @var string[]
      */
    protected static $swaggerFormats = [
        'created' => 'datetime',
'district' => null,
'ext' => null,
'high_grade' => null,
'id' => null,
'last_modified' => 'datetime',
'location' => null,
'low_grade' => null,
'mdr_number' => null,
'name' => null,
'nces_id' => null,
'phone' => null,
'principal' => null,
'school_number' => null,
'sis_id' => null,
'state_id' => null    ];

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @return array
     */
    public static function swaggerTypes()
    {
        return self::$swaggerTypes;
    }

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @return array
     */
    public static function swaggerFormats()
    {
        return self::$swaggerFormats;
    }

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'created' => 'created',
'district' => 'district',
'ext' => 'ext',
'high_grade' => 'high_grade',
'id' => 'id',
'last_modified' => 'last_modified',
'location' => 'location',
'low_grade' => 'low_grade',
'mdr_number' => 'mdr_number',
'name' => 'name',
'nces_id' => 'nces_id',
'phone' => 'phone',
'principal' => 'principal',
'school_number' => 'school_number',
'sis_id' => 'sis_id',
'state_id' => 'state_id'    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'created' => 'setCreated',
'district' => 'setDistrict',
'ext' => 'setExt',
'high_grade' => 'setHighGrade',
'id' => 'setId',
'last_modified' => 'setLastModified',
'location' => 'setLocation',
'low_grade' => 'setLowGrade',
'mdr_number' => 'setMdrNumber',
'name' => 'setName',
'nces_id' => 'setNcesId',
'phone' => 'setPhone',
'principal' => 'setPrincipal',
'school_number' => 'setSchoolNumber',
'sis_id' => 'setSisId',
'state_id' => 'setStateId'    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'created' => 'getCreated',
'district' => 'getDistrict',
'ext' => 'getExt',
'high_grade' => 'getHighGrade',
'id' => 'getId',
'last_modified' => 'getLastModified',
'location' => 'getLocation',
'low_grade' => 'getLowGrade',
'mdr_number' => 'getMdrNumber',
'name' => 'getName',
'nces_id' => 'getNcesId',
'phone' => 'getPhone',
'principal' => 'getPrincipal',
'school_number' => 'getSchoolNumber',
'sis_id' => 'getSisId',
'state_id' => 'getStateId'    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name
     *
     * @return array
     */
    public static function attributeMap()
    {
        return self::$attributeMap;
    }

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @return array
     */
    public static function setters()
    {
        return self::$setters;
    }

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @return array
     */
    public static function getters()
    {
        return self::$getters;
    }

    /**
     * The original name of the model.
     *
     * @return string
     */
    public function getModelName()
    {
        return self::$swaggerModelName;
    }

    const HIGH_GRADE_INFANT_TODDLER = 'InfantToddler';
const HIGH_GRADE_PRESCHOOL = 'Preschool';
const HIGH_GRADE_PRE_KINDERGARTEN = 'PreKindergarten';
const HIGH_GRADE_TRANSITIONAL_KINDERGARTEN = 'TransitionalKindergarten';
const HIGH_GRADE_KINDERGARTEN = 'Kindergarten';
const HIGH_GRADE__1 = '1';
const HIGH_GRADE__2 = '2';
const HIGH_GRADE__3 = '3';
const HIGH_GRADE__4 = '4';
const HIGH_GRADE__5 = '5';
const HIGH_GRADE__6 = '6';
const HIGH_GRADE__7 = '7';
const HIGH_GRADE__8 = '8';
const HIGH_GRADE__9 = '9';
const HIGH_GRADE__10 = '10';
const HIGH_GRADE__11 = '11';
const HIGH_GRADE__12 = '12';
const HIGH_GRADE__13 = '13';
const HIGH_GRADE_POST_GRADUATE = 'PostGraduate';
const HIGH_GRADE_UNGRADED = 'Ungraded';
const HIGH_GRADE_OTHER = 'Other';
const HIGH_GRADE_EMPTY = '';
const LOW_GRADE_INFANT_TODDLER = 'InfantToddler';
const LOW_GRADE_PRESCHOOL = 'Preschool';
const LOW_GRADE_PRE_KINDERGARTEN = 'PreKindergarten';
const LOW_GRADE_TRANSITIONAL_KINDERGARTEN = 'TransitionalKindergarten';
const LOW_GRADE_KINDERGARTEN = 'Kindergarten';
const LOW_GRADE__1 = '1';
const LOW_GRADE__2 = '2';
const LOW_GRADE__3 = '3';
const LOW_GRADE__4 = '4';
const LOW_GRADE__5 = '5';
const LOW_GRADE__6 = '6';
const LOW_GRADE__7 = '7';
const LOW_GRADE__8 = '8';
const LOW_GRADE__9 = '9';
const LOW_GRADE__10 = '10';
const LOW_GRADE__11 = '11';
const LOW_GRADE__12 = '12';
const LOW_GRADE__13 = '13';
const LOW_GRADE_POST_GRADUATE = 'PostGraduate';
const LOW_GRADE_UNGRADED = 'Ungraded';
const LOW_GRADE_OTHER = 'Other';
const LOW_GRADE_EMPTY = '';

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getHighGradeAllowableValues()
    {
        return [
            self::HIGH_GRADE_INFANT_TODDLER,
self::HIGH_GRADE_PRESCHOOL,
self::HIGH_GRADE_PRE_KINDERGARTEN,
self::HIGH_GRADE_TRANSITIONAL_KINDERGARTEN,
self::HIGH_GRADE_KINDERGARTEN,
self::HIGH_GRADE__1,
self::HIGH_GRADE__2,
self::HIGH_GRADE__3,
self::HIGH_GRADE__4,
self::HIGH_GRADE__5,
self::HIGH_GRADE__6,
self::HIGH_GRADE__7,
self::HIGH_GRADE__8,
self::HIGH_GRADE__9,
self::HIGH_GRADE__10,
self::HIGH_GRADE__11,
self::HIGH_GRADE__12,
self::HIGH_GRADE__13,
self::HIGH_GRADE_POST_GRADUATE,
self::HIGH_GRADE_UNGRADED,
self::HIGH_GRADE_OTHER,
self::HIGH_GRADE_EMPTY,        ];
    }
    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getLowGradeAllowableValues()
    {
        return [
            self::LOW_GRADE_INFANT_TODDLER,
self::LOW_GRADE_PRESCHOOL,
self::LOW_GRADE_PRE_KINDERGARTEN,
self::LOW_GRADE_TRANSITIONAL_KINDERGARTEN,
self::LOW_GRADE_KINDERGARTEN,
self::LOW_GRADE__1,
self::LOW_GRADE__2,
self::LOW_GRADE__3,
self::LOW_GRADE__4,
self::LOW_GRADE__5,
self::LOW_GRADE__6,
self::LOW_GRADE__7,
self::LOW_GRADE__8,
self::LOW_GRADE__9,
self::LOW_GRADE__10,
self::LOW_GRADE__11,
self::LOW_GRADE__12,
self::LOW_GRADE__13,
self::LOW_GRADE_POST_GRADUATE,
self::LOW_GRADE_UNGRADED,
self::LOW_GRADE_OTHER,
self::LOW_GRADE_EMPTY,        ];
    }

    /**
     * Associative array for storing property values
     *
     * @var mixed[]
     */
    protected $container = [];

    /**
     * Constructor
     *
     * @param mixed[] $data Associated array of property values
     *                      initializing the model
     */
    public function __construct(array $data = null)
    {
        $this->container['created'] = isset($data['created']) ? $data['created'] : null;
        $this->container['district'] = isset($data['district']) ? $data['district'] : null;
        $this->container['ext'] = isset($data['ext']) ? $data['ext'] : null;
        $this->container['high_grade'] = isset($data['high_grade']) ? $data['high_grade'] : null;
        $this->container['id'] = isset($data['id']) ? $data['id'] : null;
        $this->container['last_modified'] = isset($data['last_modified']) ? $data['last_modified'] : null;
        $this->container['location'] = isset($data['location']) ? $data['location'] : null;
        $this->container['low_grade'] = isset($data['low_grade']) ? $data['low_grade'] : null;
        $this->container['mdr_number'] = isset($data['mdr_number']) ? $data['mdr_number'] : null;
        $this->container['name'] = isset($data['name']) ? $data['name'] : null;
        $this->container['nces_id'] = isset($data['nces_id']) ? $data['nces_id'] : null;
        $this->container['phone'] = isset($data['phone']) ? $data['phone'] : null;
        $this->container['principal'] = isset($data['principal']) ? $data['principal'] : null;
        $this->container['school_number'] = isset($data['school_number']) ? $data['school_number'] : null;
        $this->container['sis_id'] = isset($data['sis_id']) ? $data['sis_id'] : null;
        $this->container['state_id'] = isset($data['state_id']) ? $data['state_id'] : null;
    }

    /**
     * Show all the invalid properties with reasons.
     *
     * @return array invalid properties with reasons
     */
    public function listInvalidProperties()
    {
        $invalidProperties = [];

        $allowedValues = $this->getHighGradeAllowableValues();
        if (!is_null($this->container['high_grade']) && !in_array($this->container['high_grade'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value for 'high_grade', must be one of '%s'",
                implode("', '", $allowedValues)
            );
        }

        $allowedValues = $this->getLowGradeAllowableValues();
        if (!is_null($this->container['low_grade']) && !in_array($this->container['low_grade'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value for 'low_grade', must be one of '%s'",
                implode("', '", $allowedValues)
            );
        }

        return $invalidProperties;
    }

    /**
     * Validate all the properties in the model
     * return true if all passed
     *
     * @return bool True if all properties are valid
     */
    public function valid()
    {
        return count($this->listInvalidProperties()) === 0;
    }


    /**
     * Gets created
     *
     * @return string
     */
    public function getCreated()
    {
        return $this->container['created'];
    }

    /**
     * Sets created
     *
     * @param string $created created
     *
     * @return $this
     */
    public function setCreated($created)
    {
        $this->container['created'] = $created;

        return $this;
    }

    /**
     * Gets district
     *
     * @return string
     */
    public function getDistrict()
    {
        return $this->container['district'];
    }

    /**
     * Sets district
     *
     * @param string $district district
     *
     * @return $this
     */
    public function setDistrict($district)
    {
        $this->container['district'] = $district;

        return $this;
    }

    /**
     * Gets ext
     *
     * @return object
     */
    public function getExt()
    {
        return $this->container['ext'];
    }

    /**
     * Sets ext
     *
     * @param object $ext ext
     *
     * @return $this
     */
    public function setExt($ext)
    {
        $this->container['ext'] = $ext;

        return $this;
    }

    /**
     * Gets high_grade
     *
     * @return string
     */
    public function getHighGrade()
    {
        return $this->container['high_grade'];
    }

    /**
     * Sets high_grade
     *
     * @param string $high_grade high_grade
     *
     * @return $this
     */
    public function setHighGrade($high_grade)
    {
        $allowedValues = $this->getHighGradeAllowableValues();
        if (!is_null($high_grade) && !in_array($high_grade, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value for 'high_grade', must be one of '%s'",
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['high_grade'] = $high_grade;

        return $this;
    }

    /**
     * Gets id
     *
     * @return string
     */
    public function getId()
    {
        return $this->container['id'];
    }

    /**
     * Sets id
     *
     * @param string $id id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->container['id'] = $id;

        return $this;
    }

    /**
     * Gets last_modified
     *
     * @return string
     */
    public function getLastModified()
    {
        return $this->container['last_modified'];
    }

    /**
     * Sets last_modified
     *
     * @param string $last_modified last_modified
     *
     * @return $this
     */
    public function setLastModified($last_modified)
    {
        $this->container['last_modified'] = $last_modified;

        return $this;
    }

    /**
     * Gets location
     *
     * @return \Clever\Client\Model\Location
     */
    public function getLocation()
    {
        return $this->container['location'];
    }

    /**
     * Sets location
     *
     * @param \Clever\Client\Model\Location $location location
     *
     * @return $this
     */
    public function setLocation($location)
    {
        $this->container['location'] = $location;

        return $this;
    }

    /**
     * Gets low_grade
     *
     * @return string
     */
    public function getLowGrade()
    {
        return $this->container['low_grade'];
    }

    /**
     * Sets low_grade
     *
     * @param string $low_grade low_grade
     *
     * @return $this
     */
    public function setLowGrade($low_grade)
    {
        $allowedValues = $this->getLowGradeAllowableValues();
        if (!is_null($low_grade) && !in_array($low_grade, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value for 'low_grade', must be one of '%s'",
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['low_grade'] = $low_grade;

        return $this;
    }

    /**
     * Gets mdr_number
     *
     * @return string
     */
    public function getMdrNumber()
    {
        return $this->container['mdr_number'];
    }

    /**
     * Sets mdr_number
     *
     * @param string $mdr_number mdr_number
     *
     * @return $this
     */
    public function setMdrNumber($mdr_number)
    {
        $this->container['mdr_number'] = $mdr_number;

        return $this;
    }

    /**
     * Gets name
     *
     * @return string
     */
    public function getName()
    {
        return $this->container['name'];
    }

    /**
     * Sets name
     *
     * @param string $name name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->container['name'] = $name;

        return $this;
    }

    /**
     * Gets nces_id
     *
     * @return string
     */
    public function getNcesId()
    {
        return $this->container['nces_id'];
    }

    /**
     * Sets nces_id
     *
     * @param string $nces_id nces_id
     *
     * @return $this
     */
    public function setNcesId($nces_id)
    {
        $this->container['nces_id'] = $nces_id;

        return $this;
    }

    /**
     * Gets phone
     *
     * @return string
     */
    public function getPhone()
    {
        return $this->container['phone'];
    }

    /**
     * Sets phone
     *
     * @param string $phone phone
     *
     * @return $this
     */
    public function setPhone($phone)
    {
        $this->container['phone'] = $phone;

        return $this;
    }

    /**
     * Gets principal
     *
     * @return \Clever\Client\Model\Principal
     */
    public function getPrincipal()
    {
        return $this->container['principal'];
    }

    /**
     * Sets principal
     *
     * @param \Clever\Client\Model\Principal $principal principal
     *
     * @return $this
     */
    public function setPrincipal($principal)
    {
        $this->container['principal'] = $principal;

        return $this;
    }

    /**
     * Gets school_number
     *
     * @return string
     */
    public function getSchoolNumber()
    {
        return $this->container['school_number'];
    }

    /**
     * Sets school_number
     *
     * @param string $school_number school_number
     *
     * @return $this
     */
    public function setSchoolNumber($school_number)
    {
        $this->container['school_number'] = $school_number;

        return $this;
    }

    /**
     * Gets sis_id
     *
     * @return string
     */
    public function getSisId()
    {
        return $this->container['sis_id'];
    }

    /**
     * Sets sis_id
     *
     * @param string $sis_id sis_id
     *
     * @return $this
     */
    public function setSisId($sis_id)
    {
        $this->container['sis_id'] = $sis_id;

        return $this;
    }

    /**
     * Gets state_id
     *
     * @return string
     */
    public function getStateId()
    {
        return $this->container['state_id'];
    }

    /**
     * Sets state_id
     *
     * @param string $state_id state_id
     *
     * @return $this
     */
    public function setStateId($state_id)
    {
        $this->container['state_id'] = $state_id;

        return $this;
    }
    /**
     * Returns true if offset exists. False otherwise.
     *
     * @param integer $offset Offset
     *
     * @return boolean
     */
    public function offsetExists($offset)
    {
        return isset($this->container[$offset]);
    }

    /**
     * Gets offset.
     *
     * @param integer $offset Offset
     *
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }

    /**
     * Sets value based on offset.
     *
     * @param integer $offset Offset
     * @param mixed   $value  Value to be set
     *
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    /**
     * Unsets offset.
     *
     * @param integer $offset Offset
     *
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }

    /**
     * Gets the string presentation of the object
     *
     * @return string
     */
    public function __toString()
    {
        if (defined('JSON_PRETTY_PRINT')) { // use JSON pretty print
            return json_encode(
                ObjectSerializer::sanitizeForSerialization($this),
                JSON_PRETTY_PRINT
            );
        }

        return json_encode(ObjectSerializer::sanitizeForSerialization($this));
    }
}
