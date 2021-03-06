<?php
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/autoload.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment/Sender.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment/Receiver.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment/AdditionalService.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment/Info.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Shipment/Parcel.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/Client.php');
require_once(Mage::getBaseDir('lib') . '/pakettikauppa/SimpleXMLElement.php');

use Pakettikauppa\Shipment;
use Pakettikauppa\Shipment\Sender;
use Pakettikauppa\Shipment\Receiver;
use Pakettikauppa\Shipment\AdditionalService;
use Pakettikauppa\Shipment\Info;
use Pakettikauppa\Shipment\Parcel;

use Pakettikauppa\Client;


class Pakettikauppa_Logistics_Helper_API extends Mage_Core_Helper_Abstract
{

    protected $client;
    protected $key;
    protected $secret;
    protected $development;
    protected $pickup_methods;

    function __construct()
    {
        $this->pickup_methods = array(
            array('id' => 'posti', 'name' => 'Posti'),
            array('id' => 'matkahuolto', 'name' => 'Matkahuolto'),
            array('id' => 'dbschenker', 'name' => 'DB Schenker')
        );
        $this->development = Mage::getStoreConfig('pakettikauppa/api/development');
        if ($this->development == 1) {
            $this->client = new Client(array('test_mode' => true));
        } else {
            $this->key = Mage::getStoreConfig('pakettikauppa/api/key');
            $this->secret = Mage::getStoreConfig('pakettikauppa/api/secret');
            if (isset($this->key) && isset($this->secret)) {
                $params['api_key'] = $this->key;
                $params['secret'] = $this->secret;
                $this->client = new Client($params);
            } else {
                Mage::throwException('Please insert API and secret key.');
            }
        }
    }

    public function getTracking($code)
    {
        $client = $this->client;
        $tracking = $client->getShipmentStatus($code);
        return json_decode($tracking);
    }


    public function getHomeDelivery($all = false)
    {

        $client = $this->client;
        $result = [];
        $methods = json_decode($client->listShippingMethods());

        // ADDING ICONS FROM SERVER
        $icons = array();
        Mage::unregister('shipping-icons');
        foreach($methods as $method){
          if($method->icon){
            $icons[$method->service_provider] = $method->icon;
          }else{
            $icons[$method->service_provider] = 'missing';
          }
        }
        Mage::register('shipping-icons', $icons);
        // ADDING ICONS FROM SERVER ENDS HERE


        if ($all == true) {
            return $methods;
        } else {
            $counter = 0;
            foreach ($methods as $method) {
                if (count($method->additional_services) > 0) {
                    foreach ($method->additional_services as $service) {
                        if ($service->service_code == '2106') {
                            $method->name = null;
                            $method->shipping_method_code = null;
                            $method->description = null;
                            $method->service_provider = null;
                            $method->additional_services = null;
                        }
                    }
                }
            }
            foreach ($methods as $method) {
                if ($method->name != null) {
                    $result[] = $method;
                }
            }
            return $result;
        }
    }

    public function getPickupPoints($zip)
    {
        $allowed_methods = array();
        foreach ($this->pickup_methods as $method) {
            if (Mage::getStoreConfig('carriers/' . $method['id'] . '_pickuppoint/active') == 1) {
                $allowed_methods[] = $method['name'];
            }
        }
        if (count($allowed_methods) > 0) {
            $allowed = implode(', ', $allowed_methods);
            $client = $this->client;
            $result = json_decode($client->searchPickupPointsByText($zip, $allowed, 10));
            return $result;
        } else {
            return null;
        }
    }

    public function createShipment($order)
    {

        $sender = new Sender();

        $store = $order->getStoreId();
        $_sender_name = Mage::getStoreConfig('pakettikauppa/sender/name', $store);
        $_sender_address = Mage::getStoreConfig('pakettikauppa/sender/address', $store);
        $_sender_city = Mage::getStoreConfig('pakettikauppa/sender/city', $store);
        $_sender_postcode = Mage::getStoreConfig('pakettikauppa/sender/postcode', $store);
        $_sender_country = Mage::getStoreConfig('pakettikauppa/sender/country', $store);

        $sender->setName1($_sender_name);
        $sender->setAddr1($_sender_address);
        $sender->setPostcode($_sender_postcode);
        $sender->setCity($_sender_city);
        $sender->setCountry($_sender_country);

        $shipping_data = $order->getShippingAddress();

        $firstname = $shipping_data->getData('firstname');
        $middlename = $shipping_data->getData('middlename');
        $lastname = $shipping_data->getData('lastname');

        $name = $firstname . ' ' . $middlename . ' ' . $lastname;

        $companyName = $shipping_data->getData('company');

        $receiver = new Receiver();
        if ($companyName == null) {
            $receiver->setName1($name);
        } else {
            $receiver->setName1($companyName);
            $receiver->setName2($name);
        }
        $receiver->setAddr1($shipping_data->getData('street'));
        $receiver->setPostcode($shipping_data->getData('postcode'));
        $receiver->setCity($shipping_data->getData('city'));
        $receiver->setCountry($shipping_data->getData('country_id'));
        $receiver->setEmail($shipping_data->getData('email'));
        $receiver->setPhone($shipping_data->getData('telephone'));

        $info = new Info();
        $info->setReference($order->getIncrementId());

        $parcel = new Parcel();
        $parcel->setReference($order->getIncrementId());
        $parcel->setWeight($order->getData('weight')); // kg

        // GET VOLUME
        $parcel->setVolume(0.001); // m3

        $shipment = new Shipment();
        $shipment->setShippingMethod($order->getData('paketikauppa_smc')); // shipping_method_code that you can get by using listShippingMethods()
        $shipment->setSender($sender);
        $shipment->setReceiver($receiver);
        $shipment->setShipmentInfo($info);
        $shipment->addParcel($parcel);

        if (strpos($order->getShippingMethod(), 'pktkp_pickuppoint') !== false) {
            $additional_service = new AdditionalService();
            $additional_service->setServiceCode(2106);
            $additional_service->addSpecifier('pickup_point_id', $order->getData('pickup_point_id'));
            $shipment->addAdditionalService($additional_service);
        }

        $payment = $order->getPayment()->getMethodInstance()->getCode();
        if($payment == 'cashondelivery'){
          $additional_service_cod = new AdditionalService();
          $additional_service_cod->setServiceCode(3101);
          $additional_service_cod->addSpecifier('amount', $order->getGrandTotal());
          $additional_service_cod->addSpecifier('account', Mage::getStoreConfig('pakettikauppa/sender/iban', $store));
          $additional_service_cod->addSpecifier('codbic', Mage::getStoreConfig('pakettikauppa/sender/codbic', $store));
          $reference_number = Mage::helper('pakettikauppa_logistics')->laskeViite($order->getIncrementId());
          $additional_service_cod->addSpecifier('reference', $reference_number);
          $shipment->addAdditionalService($additional_service_cod);
        }

        $client = $this->client;

        try {
            if ($client->createTrackingCode($shipment)) {
                if ($client->fetchShippingLabel($shipment)) {
                    $dir = Mage::getBaseDir() . "/labels";
                    if (!is_dir($dir)) {
                        mkdir($dir);
                    }
                    file_put_contents($dir . '/' . $shipment->getTrackingCode() . '.pdf', base64_decode($shipment->getPdf()));
                    // return (string)$shipment->getTrackingCode();
                    return array('number' =>  $shipment->getTrackingCode(), 'label' => base64_decode($shipment->getPdf()));
                }
            }
        } catch (\Exception $ex) {
            Mage::throwException('Shipment not created, please double check your store settings on STORE view level. Additional message: ' . $ex->getMessage());
        }
    }

}

