<?php
declare(ENCODING = 'utf-8');
namespace F3\Soap;

/*                                                                        *
 * This script belongs to the FLOW3 package "Soap".                       *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * A logging aspect
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @aspect
 */
class LoggingAspect {

	/**
	 * @inject
	 * @var \F3\FLOW3\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * Advice for logging calls of the request handler's canHandleRequest() method.
	 *
	 * @param \F3\FLOW3\AOP\JoinPointInterface
	 * @return void
	 * @after method(F3\Soap\RequestHandler->canHandleRequest())
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function logCanHandleRequestCalls(\F3\FLOW3\AOP\JoinPointInterface $joinPoint) {
		switch ($joinPoint->getResult()) {
			case \F3\Soap\RequestHandler::CANHANDLEREQUEST_OK :
				$message = 'Detected HTTP POST request at valid endpoint URI.';
			break;
			case \F3\Soap\RequestHandler::CANHANDLEREQUEST_MISSINGSOAPEXTENSION :
				$message = 'PHP SOAP extension not installed.';
			break;
			case \F3\Soap\RequestHandler::CANHANDLEREQUEST_NOPOSTREQUEST :
				$message = 'Won\'t handle request because it is not HTTP POST.';
			break;
			case \F3\Soap\RequestHandler::CANHANDLEREQUEST_WRONGSERVICEURI :
				$message = 'Won\'t handle request because it is not the expected endpoint URI.';
			break;
			default :
				$message = 'Unknown method result code (' . $joinPoint->getResult() . ')';
		}
		$this->systemLogger->log('canHandleRequest(): ' . $message, LOG_DEBUG);
	}

	/**
	 * Advice for logging handleRequest() calls
	 *
	 * @param \F3\FLOW3\AOP\JoinPointInterface
	 * @return void
	 * @before method(F3\Soap\RequestHandler->handleRequest())
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function logBeforeHandleRequestCalls(\F3\FLOW3\AOP\JoinPointInterface $joinPoint) {
		$this->systemLogger->log('Handling SOAP request.', LOG_INFO);
	}

	/**
	 * Advice for logging handleRequest() calls
	 *
	 * @param \F3\FLOW3\AOP\JoinPointInterface
	 * @return void
	 * @after method(F3\Soap\RequestHandler->handleRequest())
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function logAfterHandleRequestCalls(\F3\FLOW3\AOP\JoinPointInterface $joinPoint) {
		if ($joinPoint->hasException()) {
			$this->systemLogger->log('handleRequest() exited with exception:' . $joinPoint->getException()->getMessage(), LOG_ERR);
		} else {
			switch ($joinPoint->getResult()) {
				case \F3\Soap\RequestHandler::CANHANDLEREQUEST_OK :
					$this->systemLogger->log('handleRequest() exited successfully', LOG_DEBUG);
				break;
				case \F3\Soap\RequestHandler::HANDLEREQUEST_NOVALIDREQUEST :
					$this->systemLogger->log('Could not build request - probably no SOAP service matched the given endpoint URI', LOG_NOTICE);
				break;
			}
		}
	}
}

?>
