<?php

namespace App\Controllers;

use App\Models\UsersModel;
use App\Models\PlantationsModel;
use App\Models\PlotsModel;
use App\Models\RequestsModel;
use App\Models\LocationModel;
use App\Models\TreeCategiriesModel;
use CodeIgniter\HTTP\ResponseInterface;
use ReflectionException;
use Redis;


class Home extends BaseController
{
    private Redis $redis;
    private $accessData;

    public function __construct()
    {
        $this->redis = new Redis();
        try {
            if ($this->redis->connect("127.0.0.1")) {
                //                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            } else {
                echo lang('redisConnectionError');
                die();
            }
        } catch (\RedisException $e) {
            echo lang('redisConnectionError') . $e->getMessage();
            die();
        }
        session_write_close();
    }

    public function testRedis()
    {
        echo $this->redis->get('token');
        echo "Redis connected <br />";
        echo $this->redis->ping("hello") . "<br />";
        if ($this->redis->set("token", " hello token 1")) {
            echo "Token saved";
        }
    }

    /**
     * This function permit anyone to access Api, it may require authentication to access api
     */
    public function _secure($token = null)
    {
        //never allow Access-Control-Allow-Origin in production
        if (getenv('CI_ENVIRONMENT') == 'development') {
            $this->appendHeader();
        }
        if (!isset(apache_request_headers()["Authorization"]) && $token == null) {
            $this->response->setStatusCode(401)->setJSON(array("error" => lang('accessDenied'), "message" => lang('notHavePermissionAccessResource')))->send();
            exit();
        }
        $auth = $token == null ? apache_request_headers()["Authorization"] : 'Bearer ' . $token;
        //        $auth = $this->request->getHeader("Authorization");
        if ($auth == null || strlen($auth) < 5) {
            $this->response->setStatusCode(401)->setJSON(array("error" => lang('accessDenied'), "message" => lang('notHavePermissionAccessResource')))->send();
            exit();
        } else {
            try {
                if (preg_match("/Bearer\s((.*))/", $auth, $matches)) {
                    if (($decoded = $this->redis->get($matches[1])) !== false) {
                        $this->accessData = json_decode($decoded);
                        //check if it is current active token
                        $activeToken = $this->redis->get("user_" . $this->accessData->uid . '_active_token');
                        if ($activeToken != $matches[1]) {
                            //destroy this token, it is not the current
                            $this->redis->del($matches[1]);
                            $this->response->setStatusCode(401)->setJSON([
                                "error" => "not-active", "message" => lang('accountSignedOtherComputer')
                            ])->send();
                            exit();
                        }
                        //update session lifetime
                        $this->redis->expire($matches[1], SESSION_EXPIRATION_TIME);
                    } else {
                        $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => lang('invalidAuthentication')))->send();
                        exit();
                    }
                } else {
                    $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => lang('invalidAuthentication')))->send();
                    exit();
                }
            } catch (\Exception $e) {
                $this->response->setStatusCode(401)->setJSON(array("error" => lang('invalidToken'), "message" => $e->getMessage()))->send();
                exit();
            }
        }
    }


    public function login(): ResponseInterface
    {
        $this->appendHeader();
        $model = new UsersModel();
        $input = json_decode(file_get_contents('php://input'));
        try {
            $phone = $input->phone;
            $password = $input->password;
            $result = $model->where('phone', $phone)->get()->getRow();
            // var_dump($result); die();
            if ($result != null) {
                if (password_verify($password, $result->password)) {
                    $payload = array(
                        "iat" => time(),
                        "name" => $result->fullname,
                        "uid" => $result->id,
                        'phone' => $result->phone,
                        "psw" => password_hash($result->password, PASSWORD_DEFAULT),
                        "type" => $result->userType,
                    );
                    $token = sha1('CA' . uniqid(time()));
                    $data = array(
                        'id' => $result->id,
                        'phone' => $result->phone,
                        'name' => $result->fullname,
                        'type' => $result->userType,
                        'accessToken' => $token,
                    );

                    if ($this->redis->set($token, json_encode($payload), SESSION_EXPIRATION_TIME)) {
                        //set active token to prevent multiple login
                        $this->redis->set("user_" . $result->id . '_active_token', $token);
                        $data["redirect"] = $result->userType == 4 ? 'citizen/home' : 'admin/home';
                        return $this->response->setStatusCode(200)->setJSON($data);
                    } else {
                        return $this->response->setStatusCode(500)->setJSON(array("error" => lang('systemError'), "message" => lang('app.haveIssueEnd')));
                    }
                } else {
                    return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => lang('app.usernamePasswordNotCorrect')));
                }
            } else {
                return $this->response->setStatusCode(403)->setJSON(["error" =>
                lang('invalidLogin'), "message" => lang('app.usernamePasswordNotCorrect'), "data" => $result]);
            }
        } catch (ReflectionException $e) {
            return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => lang('app.provideRequiredData') . $e->getMessage()));
        }
    }

    public function index(): ResponseInterface
    {
        return $this->response->setJSON(['status' => 'SUCCESS', 'message' => "API is configured successfully\nDate: " . date('Y-m-d H:i:s')]);
    }

    public function getAllTreeCategories()
    {
        $this->appendHeader();
        $mdl = new TreeCategiriesModel();
        $result = $mdl->select("title as text, id as value, days-to-harvest as days")
            ->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function addNewTreeCategory()
    {
        $this->appendHeader();
        $mdl = new TreeCategiriesModel();
        $input = json_decode(file_get_contents("php://input"));
        try {
            $mdl->save([
                "title" => $input->title,
                "days-to-harvest" => $input->days
            ]);
            return $this->response->setStatusCode(200)->setJSON(["message" => "Category saved!"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage() . " - " . $e->getLine()]);
        }
    }
    public function register()
    {
        $this->appendHeader();
        $mdl = new UsersModel();
        $input = json_decode(file_get_contents("php://input"));
        try {
            $mdl->save([
                "fullname" => $input->name,
                "phone" => $input->phone,
                "password" => password_hash($input->password, PASSWORD_DEFAULT),
                "userType" => 4,
                "location" => $input->location,
                "id_number" => $input->id
            ]);
            return $this->response->setJSON(["message" => "registered successfully!"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage() . " - " . $e->getLine(), "input" => $input]);
        }
    }

    public function getProvinces()
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $result = $mdl->select("name as text, id as value")
            ->where("location_type", "PROVINCE")
            ->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }
    public function getDistricts($province = null)
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value");
        if($province != null) {
            $resultBuilder->where('parent_id', $province);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getSector($district = null )
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value");
        if($district != null) {
            $resultBuilder->where('parent_id', $district);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getCells($sector = null) 
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value");
        if($sector != null) {
            $resultBuilder->where('parent_id', $sector);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

}
