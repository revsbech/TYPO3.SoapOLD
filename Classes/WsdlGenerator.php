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
 * Dynamic WSDL Generator using reflection
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 */
class WsdlGenerator {

	/**
	 * @inject
	 * @var \F3\FLOW3\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @inject
	 * @var \F3\FLOW3\Reflection\ReflectionService
	 */
	protected $reflectionService;

	/**
	 * @inject
	 * @var \F3\Fluid\Core\Parser\TemplateParser
	 */
	protected $templateParser;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @var array
	 */
	protected $complexTypes;

	/**
	 * Default map of primitive PHP types to XSD schema types
	 *
	 * @var array
	 */
	protected $defaultTypeMap = array(
		'string' => 'xsd:string',
		'boolean' => 'xsd:boolean',
		'int' => 'xsd:integer',
		'float' => 'xsd:float'
	);

	/**
	 * @var array
	 */
	protected $operations;

	/**
	 *
	 * @param \F3\FLOW3\Reflection\ReflectionService $reflectionService
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function injectReflectionService(\F3\FLOW3\Reflection\ReflectionService $reflectionService) {
		$this->reflectionService = $reflectionService;
	}

	/**
	 * Inject the settings
	 *
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * Generate a WSDL file by reflecting the class methods and used types
	 *
	 * @param string $className
	 * @return string The WSDL XML
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function generateWsdl($className) {
		if (!preg_match('/Service$/', $className)) {
			throw new \F3\FLOW3\Exception('SOAP service class must end with "Service"', 1288984414);
		}
		if (!$this->reflectionService->isClassReflected($className)) {
			throw new \F3\FLOW3\Exception('SOAP service class "' . $className . '" is not known', 1297073339);
		}

		$serviceName = substr($className, strrpos($className, '\\') + 1);
		$servicePath = $this->getServicePath($className);

		$schema = $this->reflectOperations($className);

		$viewVariables = array_merge($schema, array(
			'serviceName' => $serviceName,
			'servicePath' => $servicePath
		));
		return $this->renderTemplate('resource://Soap/Private/Templates/Definitions.xml', $viewVariables);
	}

	/**
	 * Get the URI path of the service class
	 *
	 * @param string $className The service class name
	 * @return string The URI path
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function getServicePath($className) {
		$classParts = explode('\\', $className);
		$classParts[count($classParts) - 1] = substr($classParts[count($classParts) - 1], 0, -7);
		return $this->settings['endpointUriBasePath'] . strtolower($classParts[1] . '/' . implode('/', array_slice($classParts, 4)));
	}

	/**
	 * Reflects methods (operations) of the given class and sets
	 * information in the generator.
	 *
	 * @param string $className
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function reflectOperations($className) {
		$messages = array();
		$operations = array();
		$complexTypes = array();
		$typeMapping = $this->defaultTypeMap;
		$methodNames = $this->reflectionService->getClassMethodNames($className);
		foreach ($methodNames as $methodName) {
			if (!$this->reflectionService->isMethodPublic($className, $methodName) || strpos($methodName, 'inject') === 0) continue;
			$methodReflection = new \F3\FLOW3\Reflection\MethodReflection($className, $methodName);
			$operations[$methodName] = array(
				'name' => $methodName,
				'documentation' => $methodReflection->getDescription()
			);
			$requestMessage = $this->buildRequestMessage($className, $methodName, $complexTypes, $typeMapping);
			$messages[$requestMessage['name']] = $requestMessage;
			$responseMessage = $this->buildResponseMessage($className, $methodName, $complexTypes, $typeMapping);
			$messages[$responseMessage['name']] = $responseMessage;
		}
		return array(
			'messages' => $messages,
			'operations' => $operations,
			'complexTypes' => $complexTypes
		);
	}

	/**
	 * Build message information for the request message and recursively map
	 * types occuring as method parameters or return value.
	 *
	 * @param string $className
	 * @param string $methodName
	 * @param array &$complexTypes
	 * @param array &$typeMapping
	 * @return array Message information
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function buildRequestMessage($className, $methodName, &$complexTypes, &$typeMapping) {
		$messageName = $methodName . 'Request';
		$message = array(
			'name' => $messageName,
			'parts' => array()
		);
		$methodParameters = $this->reflectionService->getMethodParameters($className, $methodName);
		$methodTagsValues = $this->reflectionService->getMethodTagsValues($className, $methodName);
		foreach ($methodParameters as $parameterName => $methodParameter) {
			$paramAnnotation = $methodTagsValues['param'][$methodParameter['position']];
			if (preg_match('/\$\S+\ (.*)/', $paramAnnotation, $matches)) {
				$documentation = $matches[1];
			} else {
				$documentation = NULL;
			}
			$message['parts'][$parameterName] = array(
				'name' => $parameterName,
				'type' => $this->getOrCreateType($methodParameter['type'], $complexTypes, $typeMapping),
				'documentation' => $documentation
			);
		}
		return $message;
	}

	/**
	 * Build message information for the response message and recursively map
	 * types occuring as method parameters or return value.
	 *
	 * @param string $className
	 * @param string $methodName
	 * @param array &$complexTypes
	 * @param array &$typeMapping
	 * @return array Message information
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function buildResponseMessage($className, $methodName, &$complexTypes, &$typeMapping) {
		$messageName = $methodName . 'Response';
		$returnType = $this->getMethodReturnAnnotation($className, $methodName);
		$message = array(
			'name' => $messageName,
			'parts' => array(
				'returnValue' => array(
					'name' => 'returnValue',
					'type' => $this->getOrCreateType($returnType['type'], $complexTypes, $typeMapping),
					'documentation' => $returnType['description']
				)
			)
		);
		return $message;
	}

	/**
	 * Get the method return type and description
	 *
	 * @param string $className
	 * @param string $methodName
	 * @return array The method return type and description
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function getMethodReturnAnnotation($className, $methodName) {
		$methodTagsValues = $this->reflectionService->getMethodTagsValues($className, $methodName);
		if (isset($methodTagsValues['return']) && isset($methodTagsValues['return'][0])) {
			$returnAnnotations = explode(' ', $methodTagsValues['return'][0], 2);
			return array(
				'type' => $returnAnnotations[0],
				'description' => count($returnAnnotations) > 1 ? $returnAnnotations[1] : NULL
			);
		} else {
			throw new \F3\FLOW3\Exception('Could not get return value for ' . $className . '#' . $methodName, 1288984174);
		}
	}

	/**
	 * Get or create a XSD schema type from a PHP type
	 *
	 * @param string $phpType The PHP type
	 * @param array &$complexTypes The complex types
	 * @param array &$typeMapping Type mapping from PHP to schema type
	 * @return string The namespace prefixed schema type
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function getOrCreateType($phpType, &$complexTypes, &$typeMapping) {
		if (isset($typeMapping[$phpType])) {
			return $typeMapping[$phpType];
		}
		if (preg_match('/^array<(.+)>$/', $phpType, $matches)) {
			$typeName = strpos($matches[1], '\\') !== FALSE ? substr($matches[1], strrpos($matches[1], '\\') + 1) : $matches[1];
			$arrayTypeName = 'ArrayOf' . ucfirst($typeName);
			$typeMapping[$phpType] = 'tns:' . $arrayTypeName;
			$complexTypes[$arrayTypeName] = array(
				'name' => $arrayTypeName,
				'elements' => array(
					array(
						'name' => lcfirst($typeName),
						'type' => $this->getOrCreateType($matches[1], $complexTypes, $typeMapping),
						'attributes' => 'maxOccurs="unbounded" '
					)
				)
			);
		} elseif (strpos($phpType, '\\') !== FALSE) {
			$classReflection = new \F3\FLOW3\Reflection\ClassReflection($phpType);
			$typeName = substr($phpType, strrpos($phpType, '\\') + 1);
			$typeMapping[$phpType] = 'tns:' . $typeName;
			$complexTypes[$typeName] = array(
				'name' => $typeName,
				'elements' => array(),
				'documentation' => $classReflection->getDescription()
			);
			$methodNames = $this->reflectionService->getClassMethodNames($phpType);
			foreach ($methodNames as $methodName) {
				if (strpos($methodName, 'get') === 0 && $this->reflectionService->isMethodPublic($phpType, $methodName)) {
					$propertyName = lcfirst(substr($methodName, 3));
					$propertyReflection = new \F3\FLOW3\Reflection\PropertyReflection($phpType, $propertyName);

					$returnType = $this->getMethodReturnAnnotation($phpType, $methodName);
					$complexTypes[$typeName]['elements'][$propertyName] = array(
						'name' => $propertyName,
						'type' => $this->getOrCreateType($returnType['type'], $complexTypes, $typeMapping),
						'documentation' => $propertyReflection->getDescription()
					);
				}
			}
		} else {
			throw new \F3\FLOW3\Exception('Type ' . $phpType . ' not supported', 1288979369);
		}
		return $typeMapping[$phpType];
	}

	/**
	 * Render the given template file with the given variables
	 *
	 * @param string $templatePathAndFilename
	 * @param array $contextVariables
	 * @return string
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function renderTemplate($templatePathAndFilename, array $contextVariables) {
		$templateSource = \F3\FLOW3\Utility\Files::getFileContents($templatePathAndFilename, FILE_TEXT);
		if ($templateSource === FALSE) {
			throw new \F3\Fluid\Core\Exception('The template file "' . $templatePathAndFilename . '" could not be loaded.', 1225709595);
		}
		$parsedTemplate = $this->templateParser->parse($templateSource);
		$renderingContext = $this->buildRenderingContext($contextVariables);
		return $parsedTemplate->render($renderingContext);
	}

	/**
	 * Build the rendering context
	 *
	 * @param array $contextVariables
	 * @return \F3\Fluid\Core\Rendering\RenderingContextInterface
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function buildRenderingContext(array $contextVariables) {
		$renderingContext = $this->objectManager->create('F3\Fluid\Core\Rendering\RenderingContextInterface');
		$renderingContext->injectTemplateVariableContainer($this->objectManager->create('F3\Fluid\Core\ViewHelper\TemplateVariableContainer', $contextVariables));
		$renderingContext->injectViewHelperVariableContainer($this->objectManager->create('F3\Fluid\Core\ViewHelper\ViewHelperVariableContainer'));
		return $renderingContext;
	}

}
?>