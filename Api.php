<?php

namespace LGL\Clever;

use LGL\Clever\Exceptions\ApiException;
use LGL\Clever\Exceptions\Exception;
use LGL\Clever\Exceptions\InvalidRequestException;
use LGL\Clever\Exceptions\AuthenticationException;

class Api extends ApiRequest
{

    function __construct($token)
    {
        $this->setToken($token);
    }


    protected $objectMap = [
        "district"      =>  "\\LGL\\Clever\\Lib\\District",
        "school"        =>  "\\LGL\\Clever\\Lib\\School",
        "section"       =>  "\\LGL\\Clever\\Lib\\Section",
        "teacher"       =>  "\\LGL\\Clever\\Lib\\Teacher",
        "student"       =>  "\\LGL\\Clever\\Lib\\Student",
        "event"         =>  "\\LGL\\Clever\\Lib\\Event",
        "status"        =>  "\\LGL\\Clever\\Lib\\Status",
        "school_admins" =>  "\\LGL\\Clever\\Lib\\SchoolAdmin",
        "district_admins" =>  "\\LGL\\Clever\\Lib\\DistrictAdmin"
    ];


    function newObject($type, $id, array $data = [])
    {
        if (class_exists($type)) {
            $object = new $type($this->token, $id, $this->logger);
            if ($data) {
                $object = $this->unmarshal($object, $data);
            }
        }

        return $object;
    }


    function __call($name, $args = [])
    {
        if (isset($this->objectMap[$name])) {
            $object = $this->objectMap[$name];
            $Obj = new $object($args[0] ?? null, function ($url, array $query = []) {
                list($body, $code) = $this->ping($url, $query);
                if ($code != 200) {
                    return json_decode($body, true);
                }

                return json_decode($body, true);
            });

            return $Obj->retrieve();
        }
    }


    function school_admins($id = false)
    {
        $url = 'school_admins';
        if ($id)
        {
            $url = $url.'/'.$id;
        }
        list($body) = $this->ping($url, []);
        $data = json_decode($body, true);

        return $data;
    }


    function districts()
    {
        list($body, $code) = $this->ping('districts', []);
        if ($code != 200) {
            $this->relayApiError(new ApiException($body, $code));
        }
        $data = json_decode($body, true);

        return $data;
    }

    function districtAdmins($id = false) {
        $url = 'district_admins';
        if ($id) {
            $url = $url.'/'.$id;
        }
        list($body, $code) = $this->ping('district_admins', []);
        $data = json_decode($body, true);

        return $data;
    }

    function getUrl($url) {
        list($body, $code) = $this->ping($url, []);
        $data = json_decode($body, true);
        return $data;
    }

    function relayApiError(ApiException $e)
    {
        switch ($e->getCode()) {
            case 400:
            case 404:
                $e = new InvalidRequestException($e->getMessage(), $e->getCode(), $e);
                break;
            case 401:
                $e = new AuthenticationException($e->getMessage(), $e->getCode(), $e);
                break;
            case 402:
                $e = new InvalidRequestException($e->getMessage(), $e->getCode(), $e);
                break;
            default:
                $e = new Exception($e->getMessage(), $e->getCode(), $e);
                break;
        }

        if ($this->logger instanceof Log\LoggerInterface) {
            $this->logger->error($e->getMessage(), [
                "APIBASE" => static::APIBASE,
                "VERSION" => static::VERSION,
            ]);
        }

        throw $e;
    }

}
