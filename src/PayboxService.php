<?php

namespace Dosarkz\Paybox;


use Dosarkz\Paybox\Requests\NewPaymentPayboxRequest;
use Dosarkz\Paybox\Requests\PayboxStatusPaymentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @property PayboxStatus $status
 */
class PayboxService
{
    public  $status;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @param $config
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Создание нового платежа
     * @param array $data
     * @return \SimpleXMLElement
     * @throws ValidationException
     * @throws \Exception
     */
    public function generate(array $data = [])
    {
        $validator = Validator::make($data, ((new NewPaymentPayboxRequest())->rules()));
        if ($validator->fails()){
            throw new ValidationException($validator);
        }


        $this->generateSig($data, 'init_payment.php');
//        dd($data);
        $res = $this->request('post', $this->fullPath('init_payment'), $data);
        return $res;
    }

    /**
     * Получение информации о платеже
     * @param $data
     * @throws \Exception
     */
    public function paymentInfo(array $data)
    {
        $validator = Validator::make($data, ((new PayboxStatusPaymentRequest())->rules()));
        if ($validator->fails())
            throw new ValidationException($validator);

        $this->generateSig($data, 'get_status2.php');
        $req = $this->request('get', $this->fullPath('status_payment'), $data);
        $this->setStatus(new PayboxStatus());
        $this->status->setPgStatus($req->pg_status);
        $this->status->setPgPaymentId($req->pg_payment_id);
        $this->status->setPgTransactionStatus($req->pg_transaction_status);
        return $this;
    }

    /**
     * @param $route
     * @return string
     */
    private function fullPath($route)
    {
        return $this->config['url'] . '/' . $this->config['routes'][$route];
    }

    /**
     * @throws \Exception
     */
    private function request(string $verb, string $route, array $data)
    {
        $v = strtolower($verb);
        $client = new \GuzzleHttp\Client();
        $response = $client->request($v, $route, ['form_params' => $data]);
//        $response = Http::{$v}($route, $data);
        if ($response->getStatusCode() != 200)
            throw new \Exception($response->getBody());

        $data = simplexml_load_string($response->getBody());

        if ($data->pg_status != 'ok')
            throw new \Exception($response->getBody());

        return $data;
    }

    /**
     * @return PayboxStatus
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param PayboxStatus $status
     */
    public function setStatus(PayboxStatus $status)
    {
        $this->status = $status;
    }



    /**
     * @param array $data
     * @param string $type
     * @return void
     */
    private function generateSig(array &$data, string $type)
    {
        $requestForSignature = $this->makeFlatParamsArray($data);
        ksort($requestForSignature);
        array_unshift($requestForSignature, $type);
        $requestForSignature[] = $this->config['secret_key'];
        $data['pg_sig'] = md5(implode(';', $requestForSignature));
    }

    /**
     * Имя делаем вида tag001subtag001
     * Чтобы можно было потом нормально отсортировать и вложенные узлы не запутались при сортировке
     */
    private function makeFlatParamsArray($arrParams, $parent_name = '')
    {
        $arrFlatParams = [];
        $i = 0;
        foreach ($arrParams as $key => $val) {
            $i++;

            $name = $parent_name . $key . sprintf('%03d', $i);
            if (is_array($val)) {
                $arrFlatParams = array_merge($arrFlatParams, $this->makeFlatParamsArray($val, $name));
                continue;
            }
            $arrFlatParams += array($name => (string)$val);
        }

        return $arrFlatParams;
    }


}

