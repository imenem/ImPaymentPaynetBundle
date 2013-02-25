<?php

namespace Im\PaymentPaynetBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller,
    JMS\Payment\CoreBundle\Entity\FinancialTransaction,
    JMS\Payment\CoreBundle\Plugin\Exception\Exception;

class PaymentResponseController extends Controller
{
    public function validateResponseAction(FinancialTransaction $transaction)
    {
        $data = $transaction->getExtendedData();

        try
        {
            $this->get('payment.plugin.paynet_form')
                 ->processTransaction($transaction, $this->getRequest()->request->all());

            $this->addFlashSuccess('payment.success');

            return $this->redirect($data->get('success_url'));
        }
        catch (Exception $e)
        {
            $this->addFlashError('payment.error');
            $this->addFlashError("payment.status.{$transaction->getResponseCode()}");

            return $this->redirect($data->get('failed_url'));
        }
    }

    protected function addFlashSuccess($message)
    {
        $this->addFlash('success', $message);
    }

    protected function addFlashError($message)
    {
        $this->addFlash('error', $message);
    }

    protected function addFlash($type, $message)
    {
        $this->get('session')
             ->getFlashBag()
             ->add($type, $message);
    }
}