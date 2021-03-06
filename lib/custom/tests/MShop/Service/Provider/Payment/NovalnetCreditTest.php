<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2016
 */


namespace Aimeos\MShop\Service\Provider\Payment;


class NovalnetCreditTest extends \PHPUnit_Framework_TestCase
{
	private $object;
	private $ordServItem;
	private $serviceItem;
	private $context;


	protected function setUp()
	{
		if( !class_exists( 'Omnipay\Omnipay' ) ) {
			$this->markTestSkipped( 'Omnipay library not available' );
		}

		$this->context = \TestHelper::getContext();

		$serviceManager = \Aimeos\MShop\Service\Manager\Factory::createManager( $this->context );
		$this->serviceItem = $serviceManager->createItem();
		$this->serviceItem->setConfig( array( 'omnipay.type' => 'Dummy' ) );

		$this->ordServItem = \Aimeos\MShop\Factory::createManager( $this->context, 'order/base/service' )->createItem();
		$serviceItem = \Aimeos\MShop\Factory::createManager( $this->context, 'service' )->createItem();
		$serviceItem->setCode( 'test' );

		$this->object = $this->getMockBuilder( '\\Aimeos\\MShop\\Service\\Provider\\Payment\\NovalnetCredit' )
			->setMethods( array( 'getOrder', 'getOrderBase', 'saveOrder', 'saveOrderBase', 'getProvider' ) )
			->setConstructorArgs( array( $this->context, $serviceItem ) )
			->getMock();
	}


	protected function tearDown()
	{
		unset( $this->object );
	}


	public function testGetConfigFE()
	{
		$orderManager = \Aimeos\MShop\Order\Manager\Factory::createManager( $this->context );
		$orderBaseManager = $orderManager->getSubManager( 'base' );
		$search = $orderManager->createSearch();
		$expr = array(
			$search->compare( '==', 'order.type', \Aimeos\MShop\Order\Item\Base::TYPE_WEB ),
			$search->compare( '==', 'order.statuspayment', \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED )
		);
		$search->setConditions( $search->combine( '&&', $expr ) );
		$orderItems = $orderManager->searchItems( $search );

		if( ( $order = reset( $orderItems ) ) === false )
		{
			$msg = 'No Order found with statuspayment "%1$s" and type "%2$s"';
			throw new \RuntimeException( sprintf( $msg, \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED, \Aimeos\MShop\Order\Item\Base::TYPE_WEB ) );
		}

		$basket = $orderBaseManager->load( $order->getBaseId() );

		$config = $this->object->getConfigFE( $basket );

		$this->assertEquals( 'Our Unittest', $config['novalnetcredit.holder']->getDefault() );
		$this->assertArrayHasKey( 'novalnetcredit.number', $config );
		$this->assertArrayHasKey( 'novalnetcredit.year', $config );
		$this->assertArrayHasKey( 'novalnetcredit.month', $config );
		$this->assertArrayHasKey( 'novalnetcredit.cvv', $config );
	}


	public function testCheckConfigFE()
	{
		$config = array(
			'novalnetcredit.holder' => 'test user',
			'novalnetcredit.number' => '4111111111111111',
			'novalnetcredit.year' => date( 'Y' ),
			'novalnetcredit.month' => '1',
			'novalnetcredit.cvv' => '123',
		);

		$result = $this->object->checkConfigFE( $config );

		$expected = array(
			'novalnetcredit.holder' => null,
			'novalnetcredit.number' => null,
			'novalnetcredit.year' => null,
			'novalnetcredit.month' => null,
			'novalnetcredit.cvv' => null,
		);

		$this->assertEquals( $expected, $result );
	}


	public function testSetConfigFE()
	{
		$this->object->setConfigFE( $this->ordServItem, array( 'novalnetcredit.number' => '4111111111111111' ) );

		$attrItem = $this->ordServItem->getAttributeItem( 'novalnetcredit.number', 'session' );
		$this->assertInstanceOf( '\\Aimeos\\MShop\\Order\\Item\\Base\\Service\\Attribute\\Iface', $attrItem );
		$this->assertEquals( '4111111111111111', $attrItem->getValue() );
	}


	public function testProcess()
	{
		$parts = \Aimeos\MShop\Order\Manager\Base\Base::PARTS_ADDRESS | \Aimeos\MShop\Order\Manager\Base\Base::PARTS_SERVICE;
		$baseItem = $this->getOrderBase( $parts );


		$provider = $this->getMockBuilder( 'Omnipay\Dummy\Gateway' )
			->setMethods( array( 'purchase' ) )
			->getMock();

		$request = $this->getMockBuilder( '\Omnipay\Dummy\Message\AuthorizeRequest' )
			->setMethods( array( 'send' ) )
			->disableOriginalConstructor()
			->getMock();

		$response = $this->getMockBuilder( 'Omnipay\Dummy\Message\Response' )
			->setMethods( array( 'getTransactionReference', 'isSuccessful' ) )
			->disableOriginalConstructor()
			->getMock();

		$this->object->expects( $this->once() )->method( 'getOrderBase' )
			->will( $this->returnValue( $baseItem ) );

		$this->object->expects( $this->once() )->method( 'getProvider' )
			->will( $this->returnValue( $provider ) );

		$provider->expects( $this->once() )->method( 'purchase' )
			->will( $this->returnValue( $request ) );

		$request->expects( $this->once() )->method( 'send' )
			->will( $this->returnValue( $response ) );

		$response->expects( $this->once() )->method( 'isSuccessful' )
			->will( $this->returnValue( true ) );


		$result = $this->object->process( $this->getOrder() );

		$this->assertInstanceOf( '\\Aimeos\\MShop\\Common\\Item\\Helper\\Form\\Iface', $result );
	}


	protected function getOrder()
	{
		$manager = \Aimeos\MShop\Order\Manager\Factory::createManager( $this->context );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'order.datepayment', '2008-02-15 12:34:56' ) );

		$result = $manager->searchItems( $search );

		if( ( $item = reset( $result ) ) === false ) {
			throw new \RuntimeException( 'No order found' );
		}

		return $item;
	}


	protected function getOrderBase( $parts = null )
	{
		if( $parts === null ) {
			$parts = \Aimeos\MShop\Order\Manager\Base\Base::PARTS_ADDRESS | \Aimeos\MShop\Order\Manager\Base\Base::PARTS_SERVICE;
		}

		$manager = \Aimeos\MShop\Order\Manager\Factory::createManager( $this->context )->getSubmanager( 'base' );

		return $manager->load( $this->getOrder()->getBaseId(), $parts );
	}
}