<?php

namespace Im\PaymentPaynetBundle\Plugin;

use JMS\Payment\CoreBundle\Plugin\GatewayPlugin,
    JMS\Payment\CoreBundle\Plugin\ErrorBuilder,
    JMS\Payment\CoreBundle\Plugin\PluginInterface,
    JMS\Payment\CoreBundle\Model\PaymentInterface,
    JMS\Payment\CoreBundle\Model\PaymentInstructionInterface,
    JMS\Payment\CoreBundle\Model\FinancialTransactionInterface,
    JMS\Payment\CoreBundle\BrowserKit\Request,
    JMS\Payment\CoreBundle\Plugin\Exception\Exception,
    JMS\Payment\CoreBundle\Plugin\Exception\FinancialException,
    JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException,
    JMS\Payment\CoreBundle\Plugin\Exception\InvalidDataException,

    Symfony\Bundle\FrameworkBundle\Routing\Router,
    Doctrine\Common\Persistence\ObjectManager;

abstract class AbstractPaynetPlugin extends GatewayPlugin
{
    /**
     * Статус платежа: создан
     */
    const STATUS_CREATED            = 'created';

    /**
     * Статус платежа: обрабатывается
     */
    const STATUS_PROCESSING         = 'processing';

    /**
     * Статус платежа: ошибка
     */
    const STATUS_ERROR              = 'error';

    /**
     * Статус платежа: определен как мошеннический
     */
    const STATUS_FILTERED           = 'filtered';

    /**
     * Статус платежа: отклонен
     */
    const STATUS_DECLINED           = 'declined';

    /**
     * Статус платежа: подтвержден
     */
    const STATUS_APPROVED           = 'approved';

    /**
     * Статус платежа: успешно завершен
     */
    const STATUS_SUCCESS            = 'success';

    /**
     * @var \Symfony\Component\Routing\Router
     */
    protected $router;

    /**
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $entity_manager;

    /**
     * ID точки приема платежей
     *
     * @var int
     */
    protected $endpoint_id;

    /**
     * Ключ для подписи запроса
     *
     * @var string
     */
    protected $merchant_key;

    /**
     * Ссылка на гейт для отладки
     *
     * @var string
     */
    protected $sandbox_gateway;

    /**
     * Ссылка на гейт для реальных платежей
     *
     * @var string
     */
    protected $production_gateway;

    public function __construct(Router $router, ObjectManager $entity_manager, array $config)
    {
        $this->router               = $router;
        $this->entity_manager       = $entity_manager;

        $this->endpoint_id          = $config['endpoint_id'];
        $this->merchant_key         = $config['merchant_key'];
        $this->sandbox_gateway      = $config['sandbox_gateway'];
        $this->production_gateway   = $config['production_gateway'];

        parent::__construct($config['debug']);
    }

    /**
     * {@inheritdoc}
     */
    public function checkPaymentInstruction(PaymentInstructionInterface $instruction)
    {
        $errorBuilder = new ErrorBuilder;
        $data         = $instruction->getExtendedData();

        $fields =
        [
            'client_orderid',
            'order_desc',
            'ipaddress',
            'success_url',
            'failed_url'
        ];

        foreach ($fields as $field)
        {
            if (!$data->get($field))
            {
                $errorBuilder->addDataError($field, 'form.error.required');
            }
        }

        if ($errorBuilder->hasErrors())
        {
            throw $errorBuilder->getException();
        }
    }

    /**
     * Метод проверяет статус ответа от Paynet
     *
     * @param       array       $response       Непроверенный ответ от Paynet
     *
     * @return      array                       Проверенный ответ от Paynet
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException          Платеж отклонен банком
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\InvalidDataException        При выполнении платежа произошла ошибка
     */
    protected function checkResponseStatus(array &$response)
    {
        // :BUGFIX:         Imenem          13.04.12
        //
        // Так как в Paynet разный стиль именования ключей
        // для разных ответов, то нормализуем ответ
        if (isset ($response['error_message']))
        {
            $response['error-message'] = $response['error_message'];
        }

        switch ($response['status'])
        {
            case self::STATUS_APPROVED:
            case self::STATUS_PROCESSING:
            {
                return $response;
            }
            case self::STATUS_DECLINED:
            case self::STATUS_FILTERED:
            {
                $exception = new FinancialException($response['error-message']);
                $exception->addProperties($response);

                throw $exception;
            }
            case self::STATUS_ERROR:
            default:
            {
                // нормализуем статус, если он неизвестен
                $response['status'] = self::STATUS_ERROR;

                $exception = new InvalidDataException($response['error-message']);
                $exception->addProperties($response);

                throw $exception;
            }
        }
    }

    /**
     * Метод выполняет запрос к API Paynet
     *
     * @param       string      $method     Название метода API
     * @param       array       $params     Параметры запроса
     *
     * @return      array                   Ответ от API
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\InvalidDataException
     */
    protected function makeRequest($method, array $params)
    {
        $gateway_url = $this->isDebug() ? $this->sandbox_gateway : $this->production_gateway;
        $request_url = "{$gateway_url}/{$method}/{$this->endpoint_id}";

        $response   = $this->request(new Request($request_url, 'POST', $params));
        $body       = $response->getContent();

        if ($response->getStatus() !== 200 OR empty ($body))
        {
            throw new CommunicationException("Error while request.\n Response:\n" . (string) $response);
        }

        $data = [];
        parse_str(str_replace("\n", '', $body), $data);

        // :BUGFIX:         Imenem          13.04.12
        //
        // Так как в Paynet разный стиль именования ключей
        // для разных ответов, то нормализуем ответ
        if (isset ($data['error_message']))
        {
            $data['error-message'] = $data['error_message'];
        }

        if ($data['type'] == 'validation-error')
        {
            $exception = new InvalidDataException($data['error-message']);
            $exception->addProperties($data);

            throw $exception;
        }

        return $data;
    }

    /**
     * Метод переводит транзакцию в статус "завершена неудачно"
     * и передает объекты с финансовой информацией исключению
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface       $transaction        Транзакция
     * @param int                                                               $order_id           ID платежа в Paynet
     * @param string                                                            $status             Статус ответа от Paynet
     * @param \JMS\Payment\CoreBundle\Plugin\Exception\InvalidDataException     $e                  Исключение
     *
     * @return \JMS\Payment\CoreBundle\Plugin\Exception\Exception                                   Исключение
     */
    protected function failTransaction(FinancialTransactionInterface $transaction, $order_id, $status, Exception $e)
    {
        $e->setFinancialTransaction($transaction);
        $transaction->setReferenceNumber($order_id);

        /* @var $payment \JMS\Payment\CoreBundle\Model\PaymentInterface */
        $payment        = $transaction->getPayment();
        /* @var $instruction \JMS\Payment\CoreBundle\Model\PaymentInstructionInterface */
        $instruction    = $payment->getPaymentInstruction();

        // изменим состояние на "завершено неудачно"
        $transaction->setState(FinancialTransactionInterface::STATE_FAILED);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_INVALID);
        $transaction->setResponseCode($status);

        $payment->setState(PaymentInterface::STATE_FAILED);
        $instruction->setState(PaymentInstructionInterface::STATE_CLOSED);

        $this->saveEntities($transaction, $payment, $instruction);

        return $e;
    }

    /**
     * Метод переводит транзакцию с статус "завершена успешно"
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface       $transaction        Транзакция
     * @param int                                                               $order_id           ID платежа в Paynet
     */
    protected function successTransaction(FinancialTransactionInterface $transaction, $order_id)
    {
        $transaction->setReferenceNumber($order_id);

        $amount = $transaction->getRequestedAmount();
        /* @var $payment \JMS\Payment\CoreBundle\Model\PaymentInterface */
        $payment        = $transaction->getPayment();
        /* @var $instruction \JMS\Payment\CoreBundle\Model\PaymentInstructionInterface */
        $instruction    = $payment->getPaymentInstruction();

        // запишем обработанную сумму
        $transaction->setProcessedAmount($amount);

        $payment->setApprovedAmount($amount);
        $payment->setDepositedAmount($amount);

        $instruction->setApprovedAmount($amount);
        $instruction->setDepositedAmount($amount);

        // изменим состояние на "завершено успешно"
        $transaction->setState(FinancialTransactionInterface::STATE_SUCCESS);
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);

        $payment->setState(PaymentInterface::STATE_DEPOSITED);
        $instruction->setState(PaymentInstructionInterface::STATE_CLOSED);

        $this->saveEntities($transaction, $payment, $instruction);
    }

    /**
     * Метод сохраняет сущность в БД
     *
     * @param       object      $entity     Сущность для сохранения в БД
     */
    protected function saveEntity($entity)
    {
        $this->entity_manager->persist($entity);
        $this->entity_manager->flush();
    }

    /**
     * Метод сохраняет все переданные методу аргументы-сущности в БД.
     */
    protected function saveEntities()
    {
        foreach (func_get_args() as $entity)
        {
            $this->saveEntity($entity);
        }
    }
}