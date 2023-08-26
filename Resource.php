<?php

namespace LGL\Clever;

class Resource
{

    const CLEVER_DISTRICT = "district";
    const CLEVER_SCHOOL = "schools";
    const CLEVER_TEACHER = "teachers";
    const CLEVER_STUDENT = "students";
    const CLEVER_SECTION = "sections";
    const CLEVER_EVENT = "events";
    const CLEVER_ADMIN = "admins";
    const CLEVER_STATUS = "status";
    const CLEVER_SCHOOL_ADMIN = 'school_admins';
    const CLEVER_DISTRICT_ADMIN = 'district_admins';

    public $data = [];

    protected $id;

    protected $pinger;


    function __construct($id, callable $pinger)
    {
        $this->setId($id);
        $this->pinger = $pinger;
    }


    protected function makeUrl($endpoint, $options = [])
    {
        // echo "Endpoint: ".$endpoint,"\n";
        if ($this->id) {
            $url = static::BASE_URL."/{$this->id}";
        } else {
            $url = static::BASE_URL;
        }
        // ddng($endpoint, $url);
        if ($endpoint) {
            $url = "{$url}/{$endpoint}";
            if ($options) {
                $urlQuery = http_build_query($options);
                $url .= '?'.$urlQuery;
            }
        }
        return $url;
    }


    function retrieve()
    {
        $data = call_user_func($this->pinger, $this->makeUrl(""), []);
        if (is_null($data)) {
            $data = [ // LGL/Clever/Resource.php:56
                      "code"  => 30,
                      "error" => "Resource not found",
                      "data"  => null
            ];
        }
        return $this->unmarshal($data);
    }


    function unmarshal(array $data = [])
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }

        return $this;
    }


    function __set($property, $value)
    {
        $this->data[$property] = $value;
    }


    function __get($property)
    {
        if (!isset($this->data[$property])) {
            return null;
        }

        return $this->data[$property];
    }


    function setId($id)
    {
        $this->id = $this->data["id"] = $id;
    }


    function serialize()
    {
        return json_encode([
            "type" => get_class($this),
            "data" => $this->data,
        ]);
    }


    protected function getObjects($type, array $options = [])
    {
        if ($typedObject = $this->getTypedObject($type)) {
            $url = ($type == 'events') ? 'events' : $type;
            $data = call_user_func($this->pinger, $url, $options);
            if (!empty($data['data'][0]['data'])) {
                foreach ($data["data"] as $object) {
                    $Obj = new $typedObject($object["data"]["id"], $this->pinger);
                    $Objs[] = $Obj->unmarshal($object["data"]);
                }
            } elseif (!empty($data['data'])) {
                foreach ($data['data'] as $array) {
                    $Obj = new $typedObject($array["id"], $this->pinger);
                    $Objs[] = $Obj->unmarshal($array);
                }
            } else {
                // Log as an empty record
                $Obj = new $typedObject(null, $this->pinger);
                $Objs[] = $Obj;
            }


            return $Objs;
        } else {
            throw new \Exception("Invalid type: {$type}");
        }
    }


    protected function getTypedObject($type)
    {
        $objectMap = [
            static::CLEVER_DISTRICT       => __NAMESPACE__."\\Lib\\District",
            static::CLEVER_SCHOOL         => __NAMESPACE__."\\Lib\\School",
            static::CLEVER_SECTION        => __NAMESPACE__."\\Lib\\Section",
            static::CLEVER_TEACHER        => __NAMESPACE__."\\Lib\\Teacher",
            static::CLEVER_STUDENT        => __NAMESPACE__."\\Lib\\Student",
            static::CLEVER_EVENT          => __NAMESPACE__."\\Event",
            static::CLEVER_STATUS         => __NAMESPACE__."\\Status",
            static::CLEVER_ADMIN          => __NAMESPACE__."\\Lib\\Admin",
            static::CLEVER_SCHOOL_ADMIN   => __NAMESPACE__."\\Lib\\SchoolAdmin",
            static::CLEVER_DISTRICT_ADMIN => __NAMESPACE__."\\Lib\\DistrictAdmin",
        ];

        if (isset($objectMap[$type])) {
            return $objectMap[$type];
        }

        return null;
    }

}
