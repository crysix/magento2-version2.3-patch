<?php
namespace Heidelpay\Gateway\Controller\Index;

/**
 * Notification handler for the payment response
 *
 * The heidelpay payment server will call this page directly after the payment
 * process to send the result of the payment to your shop. Please make sure
 * that this page is reachable form the Internet without any authentication. 
 * 
 * The controller use cryptographic methods to protect your shop in case of   
 * fake payment responses. The plugin can not take care of man in the middle attacks, 
 * so please make sure that you use https for the checkout process. 
 *
 * @license Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 * @copyright Copyright © 2016-present Heidelberger Payment GmbH. All rights reserved.
 * @link  https://dev.heidelpay.de/magento
 * @author  Jens Richter
 *
 * @package  Heidelpay
 * @subpackage Magento2
 * @category Magento2
 */

use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Response extends \Heidelpay\Gateway\Controller\HgwAbstract
{
    protected $resultPageFactory;
    protected $logger;
    
 
    public function execute()
    {
    	$Request = $this->getRequest();
    	$data = array();
    	
    	$data['CRITERION_SECRET']				= $Request->getPost('CRITERION_SECRET');
    	
    	$data['IDENTIFICATION_TRANSACTIONID'] 	= $Request->getPOST('IDENTIFICATION_TRANSACTIONID');
    	
    	/*
    	 * validate Hash to prevent manipulation
    	 */
    	$refernceHash = $this->_encryptor->getHash($data['IDENTIFICATION_TRANSACTIONID'].$this->_encryptor->exportKeys());
    	if (empty($data['IDENTIFICATION_TRANSACTIONID']) or empty($data['CRITERION_SECRET']) or $refernceHash !== $data['CRITERION_SECRET']) {
    		echo $this->_url->getUrl ( 'hgw/index/redirect', array (
						'_forced_secure' => true,
						'_store_to_url' => true,
						'_nosid' => true 
				));
    		$this->_logger->critical("Heidelpay response form server " . $Request->getServer('REMOTE_ADDR') . " with an invalid hash. This could be some kind of manipulation.");
    		$this->_logger->critical('Heidelpay reference hash '.$refernceHash);
    		return;
    	};
    	
    	
    	$data['PROCESSING_RESULT'] 				= ($Request->getPOST('PROCESSING_RESULT') == 'ACK') ? 'ACK' : 'NOK';
    	$data['IDENTIFICATION_TRANSACTIONID'] 	= (int)$Request->getPOST('IDENTIFICATION_TRANSACTIONID');
    	$data['PROCESSING_STATUS_CODE']			= (int)$Request->getPOST('PROCESSING_STATUS_CODE');
    	$data['PROCESSING_RETURN']				= $Request->getPOST('PROCESSING_RETURN');
    	$data['PROCESSING_RETURN_CODE']			= $Request->getPOST('PROCESSING_RETURN_CODE');
    	$data['PAYMENT_CODE']					= $Request->getPOST('PAYMENT_CODE');
    	$data['IDENTIFICATION_UNIQUEID']		= $Request->getPOST('IDENTIFICATION_UNIQUEID');
    	$data['IDENTIFICATION_SHORTID'] 		= $Request->getPOST('IDENTIFICATION_SHORTID');
    	$data['IDENTIFICATION_SHOPPERID'] 		= (int)$Request->getPOST('IDENTIFICATION_SHOPPERID');
    	$data['CRITERION_GUEST'] 				= $Request->getPOST('CRITERION_GUEST');
    	
    	/**
    	 * information 
    	 */
    	 
    	$data['TRANSACTION_MODE']				= ($Request->getPOST('TRANSACTION_MODE') == 'LIVE') ? 'LIVE' : 'CONNECTOR_TEST';
    	$data['PRESENTATION_CURRENCY']			= $Request->getPOST('PRESENTATION_CURRENCY');
    	$data['PRESENTATION_AMOUNT']			= floatval($Request->getPOST('PRESENTATION_AMOUNT'));
    	$data['ACCOUNT_BRAND']					= $Request->getPOST('ACCOUNT_BRAND');
    	
    	$PaymentCode = $this->_paymentHelper->splitPaymentCode ($data['PAYMENT_CODE']);
    	
    	$data['PAYMENT_METHODE']	= $PaymentCode[0];
    	$data['PAYMENT_TYPE']		= $PaymentCode[1];
    	$data['SOURCE']				= 'RESPONSE';
    	
    	if ($data['PAYMENT_CODE'] == "PP.PA") {
    	    $data['CONNECTOR_ACCOUNT_HOLDER'] = $Request->getPOST('CONNECTOR_ACCOUNT_HOLDER');
    	    $data['CONNECTOR_ACCOUNT_IBAN'] = $Request->getPOST('CONNECTOR_ACCOUNT_IBAN');
    	    $data['CONNECTOR_ACCOUNT_BIC'] = $Request->getPOST('CONNECTOR_ACCOUNT_BIC');
    	    
    	}
    	
    	$this->_logger->addDebug('Heidelpay response postdata : '.print_r($data,1));
    	
    	
    	
    	
    	if ($data['PROCESSING_RESULT'] == 'ACK'){
    		
    		try {
    			$quote = $this->_objectManager->create('Magento\Quote\Model\Quote')->load($data['IDENTIFICATION_TRANSACTIONID']);
    			
    			/** in case of quest checkout */
    			if($data['CRITERION_GUEST'] === 'true') {
    				$quote->setCustomerId(null)
    					->setCustomerEmail($quote->getBillingAddress()->getEmail())
    					->setCustomerIsGuest(true)
    					->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
    			}
    			
    			$order = $this->_cartManagement->submit($quote);
    		} catch (\Exception $e) {
    			$this->_logger->addDebug('Heidelpay Response save order. '.$e->getMessage());
    		}
    		
    		$data['ORDER_ID']	= $order->getIncrementId();
    		
    		
    		$this->_paymentHelper->mapStatus (
    				$data,
    				$order
    				);
    		$order->save();
    		
    	}
    	
    	$url = $this->_url->getUrl ( 'hgw/index/redirect', array (
    			'_forced_secure' => true,
    			'_store_to_url' => true,
    			'_nosid' => true
    	));
    	
    	$this->_logger->addDebug('Heidelpay respose url : '.$url);
    	echo $url;
    	
    	try {
    				$model = $this->_objectManager->create('Heidelpay\Gateway\Model\Transaction');
    				$model->setData('payment_methode', $data['PAYMENT_METHODE']);
    				$model->setData('payment_type', $data['PAYMENT_TYPE']);
    				$model->setData('transactionid', $data['IDENTIFICATION_TRANSACTIONID']);
    				$model->setData('uniqeid', $data['IDENTIFICATION_UNIQUEID']);
    				$model->setData('shortid', $data['IDENTIFICATION_SHORTID']);
    				$model->setData('statuscode', $data['PROCESSING_STATUS_CODE']);
    				$model->setData('result', $data['PROCESSING_RESULT']);
    				$model->setData('return', $data['PROCESSING_RETURN']);
    				$model->setData('returncode', $data['PROCESSING_RETURN_CODE']);
    				$model->setData('jsonresponse', json_encode($data));
    				$model->setData('source', $data['SOURCE']);
    				$model->save();
    	} catch (\Exception $e) {
    		$this->_logger->error('Heidelpay Response save transaction. '.$e->getMessage());
    	}
    	
    	
    	
    	
    	
    	
    }
}