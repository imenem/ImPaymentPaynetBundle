<?php

namespace Im\PaymentPaynetBundle\Plugin;

use JMS\Payment\CoreBundle\Plugin\PluginInterface,
    JMS\Payment\CoreBundle\Model\FinancialTransactionInterface,
    JMS\Payment\CoreBundle\Plugin\Exception\Exception,
    JMS\Payment\CoreBundle\Plugin\Exception\InvalidDataException,
    JMS\Payment\CoreBundle\Plugin\Exception\Action\VisitUrl,
    JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;

class PaynetFormPlugin extends AbstractPaynetPlugin
{
    /**
     * {@inheritdoc}
     */
    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $response = $this->requestForm($this->prepareParams($transaction));

        try
        {
            $this->validateForm($response);
        }
        catch (Exception $e)
        {
            throw $this->failTransaction($transaction, $response['paynet-order-id'], $response['status'], $e);
        }

        $action = new ActionRequiredException('3D form redirect');
        $action->setFinancialTransaction($transaction);
        $action->setAction(new VisitUrl($response['redirect-url']));

        throw $action;
    }

    /**
     * Метод изменяет статус транзакции в зависимости от ответа Paynet
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface   $transaction    Транзакция
     * @param array                                                         $response       Данные от Paynet
     */
    public function processTransaction(FinancialTransactionInterface $transaction, array $response)
    {
        $errors = $this->checkRequiredFields($response);

        if (!empty ($errors))
        {
            $e = new InvalidDataException('Required keys missed');
            $e->setProperties(['missed_keys' => $errors]);

            $order_id = isset ($response['orderid']) ? $response['orderid'] : 0;
            $status   = isset ($response['status'])  ? $response['status']  : self::STATUS_ERROR;

            throw $this->failTransaction($transaction, $order_id, $status, $e);
        }

        $response = array_merge($this->getResponseSpec(), $response);

        try
        {
            $this->checkResponseStatus($response);
        }
        catch (Exception $e)
        {
            throw $this->failTransaction($transaction, $response['orderid'], $response['status'], $e);
        }

        $this->successTransaction($transaction, $response['orderid']);
    }

    /**
     * {@inheritdoc}
     */
    public function processes($payment_system_name)
    {
        return ($payment_system_name == 'paynet_form');
    }

    /**
     * Метод заполняет спецификацию параметров для запроса к API данными,
     * переданными в транзакции. После заполнения запрос подписывается
     * с помощью ключа мерчанта.
     *
     * @param       \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface     $transaction
     *
     * @return      array       Подготовленные параметры запроса
     */
    protected function prepareParams(FinancialTransactionInterface $transaction)
    {
        $params = $this->getParamsSpec();
        $data   = $transaction->getExtendedData();

        foreach ($params as $key => &$value)
        {
            if ($data->has($key))
            {
                $value = $data->get($key);
            }
        }

        $params['amount']       = $transaction->getRequestedAmount();
        $params['currency']     = $transaction->getPayment()->getPaymentInstruction()->getCurrency();

        $this->saveEntity($transaction);

        $params['redirect_url'] = $this->router->generate('validate_payment_response',
                                                         ['id' => $transaction->getId()], true);

        return $this->signParams($params);
    }

    /**
     * Метод подписывает параметры запроса ключом мерчанта
     *
     * @param       array       $params         Неподписанные параметры
     *
     * @return      array                       Подписанные параметры
     */
    protected function signParams(array &$params)
    {
        if (!$params['control'])
        {
            $params['control'] = sha1
            (
                $this->endpoint_id .
                $params['client_orderid'] .
                $params['amount'] * 100 .
                $params['email'] .
                $this->merchant_key
            );
        }

        return $params;
    }

    /**
     * Метод проверяет корректность подписи ответа
     *
     * @param       array       $response       Ответ от Paynet
     *
     * @return      bool                        True, если подпись корректна
     */
    protected function checkResponseSign(array $response)
    {
        return $response['control'] === sha1
        (
            $response['status'] .
            $response['orderid'] .
            $response['merchant_order'] .
            $this->merchant_key
        );
    }

    /**
     * Метод возвращает спецификацию параметров запроса
     *
     * @return      array
     */
    protected function getParamsSpec()
    {
         // Спецификация параметров
        return
        [
            // обязательные
            'client_orderid'    => '',
            'order_desc'        => '',
            'amount'            => '',
            'currency'          => '',
            'ipaddress'         => '',
            'redirect_url'      => '',
            'control'           => '',

            // необязательные
            'first_name'        => '',
            'last_name'         => '',
            'ssn'               => '',
            'birthday'          => '',
            'cell_phone'        => '',
            'state'             => '',
            'site_url'          => '',

            // обязательные, фейковые значения по-умолчанию
            'address1'          => 'Not specified',
            'city'              => 'Not specified',
            'zip_code'          => '000000',
            'country'           => 'RU',
            'phone'             => 'Not specified',
            'email'             => 'email@example.org'
        ];
    }

    /**
     * Метод возвращает спецификацию параметров данных,
     * передаваемых от Paynet при завершении оплаты через форму
     *
     * @return      array
     */
    protected function getResponseSpec()
    {
        return
        [
            // обязательные ключи ответа
            'merchant_order'        => true,
            'orderid'               => true,
            'status'                => true,
            'control'               => true,
            // необязательные
            'client_orderid'        => false,
            'descriptor'            => false,
            'gate-partial-reversal' => false,
            'error_message'         => false,
        ];
    }

    /**
     * Метод запрашивает параметры для переадресации на платежную форму
     *
     * @param       array       $params     Параметры запроса
     *
     * @return      array                   Параметры ответа от Paynet
     */
    protected function requestForm(array $params)
    {
        return $this->makeRequest('sale-form', $params);
    }

    /**
     * Метод проверяет параметры для переадресации на платежную форму
     *
     * @param       array       $response   Параметры ответа от Paynet
     *
     * @return      array                   Параметры ответа от Paynet
     */
    protected function validateForm(array &$response)
    {
        // :BUGFIX:         Imenem          11.04.12
        //
        // Paynet не всегда возвращает поле status для этого запроса,
        // поэтому будем считать, что если его нет, то платеж обрабатывается
        if (!isset ($response['status']))
        {
            $response['status'] = self::STATUS_PROCESSING;
        }

        return $this->checkResponseStatus($response);
    }

    /**
     * Метод проверяет, заданы ли все необходимые поля в параметрах,
     * передаваемых от Paynet при завершении оплаты через форму
     *
     * @param       array       $response   Параметры, передаваемые Paynet
     *
     * @return      array                   Массив с найденным ошибками
     */
    protected function checkRequiredFields(array $response)
    {
        $errors = [];

        foreach ($this->getResponseSpec() as $key => $required)
        {
            if ($required === true AND empty ($response[$key]))
            {
                $errors[] = $key;
            }
        }

        if (empty ($response['status']))
        {
            $response['status'] = self::STATUS_ERROR;
        }

        return $errors;
    }
}