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
            $this->response->setStatusCode(401)->setJSON(array("error" => lang('accessDenied'), "message" => "you are not authorized here"))->send();
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
                        'location' => $result->location,
                        'accessToken' => $token,
                    );

                    if ($this->redis->set($token, json_encode($payload), SESSION_EXPIRATION_TIME)) {
                        //set active token to prevent multiple login
                        $this->redis->set("user_" . $result->id . '_active_token', $token);
                        $data["redirect"] = $result->userType == 4 ? 'citizen/home' : ($result->userType == 1 ? 'admin/home' : 'PO/home');
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
        return $this->response->setJSON(['status' => 'SUCCESS', 'message' => "API is configured well and successfully\nDate: " . date('Y-m-d H:i:s')]);
    }

    public function getAllTreeCategories()
    {
        $this->appendHeader();
        $mdl = new TreeCategiriesModel();
        $result = $mdl->select("title as text, id as value, days_to_harvest as days")
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
                "days_to_harvest" => $input->days
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
        $check = $mdl->where("phone", $input->phone)->get()->getResultArray();
        if(!Empty($check)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Phone number is already registered"]);
        }
        if(empty($input->phone) || strlen($input->phone) != 10) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Phone number is required and must be 10 digits"]);
        }
        if(empty($input->id) || strlen($input->id) != 16) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Your ID number is required and must be 10 digits"]);
        }
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
        $resultBuilder = $mdl->select("name as text, id as value")
            ->where("location_type", "DISTRICT");
        if ($province != null) {
            $resultBuilder->where('parent_id', $province);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getSector($district = null)
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value")
            ->where("location_type", "SECTOR");
        if ($district != null) {
            $resultBuilder->where('parent_id', $district);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getCells($sector = null)
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value")
            ->where("location_type", "CELL");
        if ($sector != null) {
            $resultBuilder->where('parent_id', $sector);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function getvillages($cell = null)
    {
        $this->appendHeader();
        $mdl = new LocationModel();
        $resultBuilder = $mdl->select("name as text, id as value")
            ->where("location_type", "VILLAGE");
        if ($cell != null) {
            $resultBuilder->where('parent_id', $cell);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON(["data" => $result]);
    }

    public function addUser()
    {
        $this->appendHeader();
        $mdl = new UsersModel();
        $input = json_decode(file_get_contents("php://input"));
        $password = 'abcdef';
        try {
            $mdl->save([
                "fullname" => $input->name,
                "phone" => $input->phone,
                "password" => password_hash($password, PASSWORD_DEFAULT),
                "userType" => 2,
                "location" => $input->location
            ]);
            return $this->response->setJSON(["message" => "registered successfully!"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage() . " - " . $e->getLine(), "input" => $input]);
        }
    }

    public function getUsers()
    {
        $this->appendHeader();
        $mdl = new UsersModel();
        $result = $mdl->select("users.fullname,users.phone,sct.name as sector,dct.name as district,prc.name as province")
            ->join("location sct", "sct.id = users.location")
            ->join("location dct", "dct.id = sct.parent_id")
            ->join("location prc", "prc.id = dct.parent_id")
            ->where("userType", 2)
            ->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function saveForest()
    {
        $this->appendHeader();
        $mdl = new PlotsModel();

        try {
            $target_dir = "./public/assets/uploads/";

            // var_dump($this->request->getPost("area")); die();
            $pdfFiles = $_FILES['pdfFiles']['name'];
            $path = pathinfo($pdfFiles);
            $pdfFilesname = $path['filename'];
            $extPdf = $path['extension'];
            $path_pdfFilesname_ext = $target_dir . time() . $pdfFilesname . "." . $extPdf;


            $imgFiles = $_FILES['imgFiles']['name'];
            $path = pathinfo($imgFiles);
            $imgFilesname = $path['filename'];
            $extmg = $path['extension'];
            $path_imgFilesname_ext = $target_dir . time() .$imgFilesname . "." . $extmg;

            $mdl->save([
                "ownerId" => $this->request->getPost("uid"),
                "area" => $this->request->getPost("area"),
                "province" => $this->request->getPost("province"),
                "district" => $this->request->getPost("district"),
                "sector" => $this->request->getPost("sector"),
                "cell" => $this->request->getPost("cell"),
                "village" => $this->request->getPost("village"),
                "permission" => $path_pdfFilesname_ext,
                "upi" => $this->request->getPost("upi"),
                "upi_image" => $path_imgFilesname_ext,
            ]);

            move_uploaded_file($_FILES['pdfFiles']['tmp_name'], $path_imgFilesname_ext);
            move_uploaded_file($_FILES['imgFiles']['tmp_name'], $path_pdfFilesname_ext);
            return $this->response->setJSON(["message" => "Forest registered"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
        }
    }

    public function getForests($id = null)
    {
        $this->appendHeader();
        $mdl = new PlotsModel();
        $sector = $this->request->getGet("sector");
        $resultBuilder = $mdl->select("plots.id as value,u.fullname as names,upi as text,area,l.name as sector")
                                ->join("users u", "u.id = plots.ownerId")
                                ->join("location l", "l.id = plots.sector");
        if($id != null) {
            $resultBuilder->where("plots.ownerId", $id);
        }
        if(!empty($sector)) {
            $resultBuilder->where("plots.sector", $sector);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }
    
    public function saveRequest()
    {
        $this->appendHeader();
        $mdl = new RequestsModel();
        $plMdl = new PlantationsModel();
        $input = json_decode(file_get_Contents("php://input"));
        if(empty($input->qty) || $input->qty <= 0) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Check quantity of trees to harvest and try again"]);
        }
        if(empty($input->trc)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Trees category is required"]);
        }
        $today = date('Y-m-d');
        $inPlantation = $plMdl->select("coalesce(SUM(num_trees),0) as trees")
                        ->where("plot_id", $input->forest)
                        ->where("treeType", $input->trc)
                        ->where("harvest_date <=", $today)
                        ->first();

        $inRequest = $mdl->select("coalesce(SUM(quantity),0) as trees")
                        ->where("forest", $input->forest)
                        ->where("category", $input->trc)
                        ->where("status", 1)
                        ->first();
        $remain = $inPlantation['trees'] - $inRequest['trees'];
        if($remain > 0 && $input->qty > $remain) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "you don't have that much trees of this kind in forest"]);
        }
        try {
            $mdl->save([
                "ownerId" => $input->owner,
                "category" => $input->trc,
                "forest" => $input->forest,
                "quantity" => $input->qty,
            ]);
        return $this->response->setJSON(["message" => "Request Saved"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Request not Saved! try again later"]);
        }
    }

    public function savePlantation()
    {
        $this->appendHeader();
        $mdl = new PlantationsModel();
        $trcMdl = new TreeCategiriesModel();
        $input = json_decode(file_get_Contents("php://input"));
        if(empty($input->qty)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Check quantity of trees planted and try again"]);
        }
        if(empty($input->trc)) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Trees category is required"]);
        }
        $days = $trcMdl->select("days_to_harvest as num")->where("id", $input->trc)->first();
        $now = time();
        $harvestStamp = $now + (86400 * $days['num']);
        try {
            $mdl->save([
                "ownerId" => $input->owner,
                "plot_id" => $input->forest,
                "num_trees" => $input->qty,
                "treeType" => $input->trc,
                "harvest_date" => date('Y-m-d', $harvestStamp),
                "status" => 1
            ]);
        return $this->response->setJSON(["message" => "plantation Saved"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => "Request not Saved! try again later"]);
        }
    }

    public function getAllRequest($id = null)
    {
        $this->appendHeader();
        $mdl = new RequestsModel();
        $sector = $this->request->getGet("sector");
        $resultBuilder = $mdl->select("DATE(requests.created_at) as date,requests.id,u.fullname as name, u.phone,s.name as sector,d.name as district,p.upi, quantity, if(requests.status = 0,'PENDING',if(requests.status = 1,'APPROVED','DENIED')) as status, requests.status as stCode")
                                ->join("users u", "u.id = requests.ownerId")
                                ->join("plots p" , "p.id = requests.forest")
                                ->join("location s", "s.id = p.sector")
                                ->join("location d", "d.id = p.district")
                                ->orderBy("requests.status");
        if($id != null) {
            $resultBuilder->where("requests.ownerId", $id);
        }
        if(!empty($sector)) {
            $resultBuilder->where("s.id", $sector);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function getAllPlatations($id = null)
    {
        $this->appendHeader();
        $mdl = new PlantationsModel();
        $sector = $this->request->getGet("sector");
        $resultBuilder = $mdl->select("u.fullname as name, u.phone,s.name as sector,d.name as district,p.upi, num_trees as trees, DATE(plantation_date) as date")
                                ->join("users u", "u.id = plantations.ownerId", "LEFT")
                                ->join("plots p" , "p.id = plantations.plot_id", "LEFT")
                                ->join("location s", "s.id = p.sector", "LEFT")
                                ->join("location d", "d.id = p.district", "LEFT");
        if($id != null) {
            $resultBuilder->where("plantations.ownerId", $id);
        }
        if(!empty($sector)) {
            $resultBuilder->where("s.id", $sector);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    public function approveRequest()
    {
        $this->appendHeader();
        $mdl = new RequestsModel();
        $input = json_decode(file_get_contents("php://input"));
        try {
            $mdl->save([
                "id" => $input->id,
                "status" => $input->status
            ]);
            return $this->response->setJSON(["message" => "Request status Changed successfully"]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
        }
        
    }
}
