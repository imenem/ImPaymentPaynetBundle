<?php

namespace Im\PaymentPaynetBundle\Form;

use Symfony\Component\Form\AbstractType,
    Symfony\Component\Form\FormBuilderInterface;

class PaynetFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('client_orderid', 'integer')
            ->add('order_desc',     'text')
            ->add('ipaddress',      'text')
            ->add('success_url',    'text')
            ->add('failed_url',     'text')
        ;
    }

    public function getName()
    {
        return 'paynet_form';
    }
}
