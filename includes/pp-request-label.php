<?php

require_once("../../../../wp-load.php");

$requestLabel = new PP_Request_Label($_GET['order_id']);
$requestLabel->requestLabel();

class PP_Request_Label
{
    public function __construct($orderId)
    {
        $this->orderId = $orderId;
        $this->order = new WC_Order($this->orderId);
        $this->url = 'http://pakketpartner.app/api/v1/shipments';
    }

    public function requestLabel()
    {
        $requestData = $this->getRequestData();

        try {
            $result = $this->sendRequest($requestData);

            update_post_meta($this->order->id, 'pp_pdf_label_url', $result['data']['label_url_pdf']);
            update_post_meta($this->order->id, 'pp_tracking_code', $result['data']['tracking_code']);
            update_post_meta($this->order->id, 'pp_tracking_url', $result['data']['tracking_url']);

            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Exception $e) {
            $errorMessage = 'Fout: Kan geen label opvragen bij Pakketpartner';

            $errors = json_decode($e->getMessage(), true);
            $flatErrorArray = [];

            foreach ($errors['errors'] as $error) {
                $flatErrorArray[] = $error;
            }

            if (count($flatErrorArray) > 0) {
                $errorMessage = implode(', ', $flatErrorArray);
            }

            echo json_encode(['success' => false, 'data' => $errorMessage]);
        }
    }

    private function getRequestData()
    {
        $accountCarrierServiceHash = get_post_meta($this->order->id, 'pp_account_carrier_service_hash')[0];
        $accountCarrierServiceHashParts = explode(':', $accountCarrierServiceHash);
        $accountCarrierServiceHash = $accountCarrierServiceHashParts[0];

        return json_encode([
            'carrier_service' => $accountCarrierServiceHash,
            'order_reference' => $this->order->id,
            'recipient' => [
                'company' => $this->order->shipping_company,
                'name' => $this->order->shipping_first_name . ' ' . $this->order->shipping_last_name,
                'address_line_1' => $this->order->shipping_address_1,
                'zipcode' => $this->order->shipping_postcode,
                'city' => $this->order->shipping_city,
                'country' => $this->order->shipping_country,
                'phone' => $this->order->billing_phone,
                'email' => $this->order->billing_email,
            ]
        ]);
    }

    private function sendRequest($data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_USERPWD, get_option('pakketpartner_api_key') . ':');

        if (!is_null($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $apiresult = curl_exec($ch);
        $responseinfo = curl_getinfo($ch);

        if (curl_errno($ch)) {
            $errorMessage = curl_error($ch);
            $errorNumber = curl_errno($ch);

            curl_close($ch);

            throw new Exception($errorMessage, $errorNumber);
        }

        curl_close($ch);

        if (!in_array($responseinfo['http_code'], [200, 201])) {
            throw new Exception($apiresult);
        }

        return json_decode($apiresult, true);
    }
}