<?php

/* Copyright (c) 1998-2010 ILIAS open source, Extended GPL, see docs/LICENSE */

use ceLTIc\LTI\OAuth\OAuthRequest;
use ceLTIc\LTI\OAuth\OAuthUtil;
use ceLTIc\LTI\ToolProvider;
use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\OAuth;
use ceLTIc\LTI\Context;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\User;

require_once dirname(__FILE__) . '/class.ilLTIUser.php';
/**
 * LTI provider for LTI launch
 *
 * @author Stefan Meyer <smeyer.ilias@gmx.de>
 * @author Uwe Kohnle <kohnle@internetlehrer-gmbh.de>
 *
 */
class ilLTIToolProvider extends ToolProvider
{
    /**
     * @var \ilLogger
     */
    protected $logger = null;


    public $debugMode = true; //ACHTUNG weg bei Produktiv-Umgebung
    /**
 * Permitted LTI versions for messages.
 */
    private static $LTI_VERSIONS = array(self::LTI_VERSION1, self::LTI_VERSION2);
    /**
     * List of supported message types and associated class methods.
     */
    private static $MESSAGE_TYPES = array('basic-lti-launch-request' => 'onLaunch',
                                          'ContentItemSelectionRequest' => 'onContentItem',
                                          'ToolProxyRegistrationRequest' => 'register');
    /**
     * List of supported message types and associated class methods
     *
     * @var array $METHOD_NAMES
     */
    private static $METHOD_NAMES = array('basic-lti-launch-request' => 'onLaunch',
                                         'ContentItemSelectionRequest' => 'onContentItem',
                                         'ToolProxyRegistrationRequest' => 'onRegister');
    /**
     * Names of LTI parameters to be retained in the consumer settings property.
     *
     * @var array $LTI_CONSUMER_SETTING_NAMES
     */
    private static $LTI_CONSUMER_SETTING_NAMES = array('custom_tc_profile_url', 'custom_system_setting_url');
    /**
     * Names of LTI parameters to be retained in the context settings property.
     *
     * @var array $LTI_CONTEXT_SETTING_NAMES
     */
    private static $LTI_CONTEXT_SETTING_NAMES = array('custom_context_setting_url',
                                                      'custom_lineitems_url', 'custom_results_url',
                                                      'custom_context_memberships_url');
    /**
     * Names of LTI parameters to be retained in the resource link settings property.
     *
     * @var array $LTI_RESOURCE_LINK_SETTING_NAMES
     */
    private static $LTI_RESOURCE_LINK_SETTING_NAMES = array('lis_result_sourcedid', 'lis_outcome_service_url',
                                                            'ext_ims_lis_basic_outcome_url', 'ext_ims_lis_resultvalue_sourcedids',
                                                            'ext_ims_lis_memberships_id', 'ext_ims_lis_memberships_url',
                                                            'ext_ims_lti_tool_setting', 'ext_ims_lti_tool_setting_id', 'ext_ims_lti_tool_setting_url',
                                                            'custom_link_setting_url',
                                                            'custom_lineitem_url', 'custom_result_url');
    /**
     * Names of LTI custom parameter substitution variables (or capabilities) and their associated default message parameter names.
     *
     * @var array $CUSTOM_SUBSTITUTION_VARIABLES
     */
    private static $CUSTOM_SUBSTITUTION_VARIABLES = array('User.id' => 'user_id',
                                                          'User.image' => 'user_image',
                                                          'User.username' => 'username',
                                                          'User.scope.mentor' => 'role_scope_mentor',
                                                          'Membership.role' => 'roles',
                                                          'Person.sourcedId' => 'lis_person_sourcedid',
                                                          'Person.name.full' => 'lis_person_name_full',
                                                          'Person.name.family' => 'lis_person_name_family',
                                                          'Person.name.given' => 'lis_person_name_given',
                                                          'Person.email.primary' => 'lis_person_contact_email_primary',
                                                          'Context.id' => 'context_id',
                                                          'Context.type' => 'context_type',
                                                          'Context.title' => 'context_title',
                                                          'Context.label' => 'context_label',
                                                          'CourseOffering.sourcedId' => 'lis_course_offering_sourcedid',
                                                          'CourseSection.sourcedId' => 'lis_course_section_sourcedid',
                                                          'CourseSection.label' => 'context_label',
                                                          'CourseSection.title' => 'context_title',
                                                          'ResourceLink.id' => 'resource_link_id',
                                                          'ResourceLink.title' => 'resource_link_title',
                                                          'ResourceLink.description' => 'resource_link_description',
                                                          'Result.sourcedId' => 'lis_result_sourcedid',
                                                          'BasicOutcome.url' => 'lis_outcome_service_url',
                                                          'ToolConsumerProfile.url' => 'custom_tc_profile_url',
                                                          'ToolProxy.url' => 'tool_proxy_url',
                                                          'ToolProxy.custom.url' => 'custom_system_setting_url',
                                                          'ToolProxyBinding.custom.url' => 'custom_context_setting_url',
                                                          'LtiLink.custom.url' => 'custom_link_setting_url',
                                                          'LineItems.url' => 'custom_lineitems_url',
                                                          'LineItem.url' => 'custom_lineitem_url',
                                                          'Results.url' => 'custom_results_url',
                                                          'Result.url' => 'custom_result_url',
                                                          'ToolProxyBinding.memberships.url' => 'custom_context_memberships_url');

    /**
     * LTI parameter constraints for auto validation checks.
     *
     * @var array|null $constraints
     */
    private $constraints = null;

        /**
         * ilLTIToolProvider constructor.
         * @param DataConnector $dataConnector
         */
    public function __construct(DataConnector $dataConnector)
    {
        global $DIC;

        $this->logger = $DIC->logger()->lti();

        parent::__construct($dataConnector);

        $this->constraints = [];
    }


    /**
 * Process an incoming request
 */
    public function handleRequest()
    {
        if ($this->ok) {
            if ($this->authenticate()) {
                $this->doCallback();
            }
        }
        // if return url is given, this redirects in case of errors
        $this->result();
        return $this->ok;
    }

    ###
    ###    PROTECTED METHODS
    ###

    /**
     * Process a valid launch request
     *
     * @return boolean True if no error
     */
    protected function onLaunch()
    {
        // save/update current user
        if ($this->user instanceof User) {
            $this->user->save();
        }

        if ($this->context instanceof Context) {
            $this->context->save();
        }

        if ($this->resourceLink instanceof ResourceLink) {
            $this->resourceLink->save();
        }
    }

    /**
     * Process a valid content-item request
     *
     * @return boolean True if no error
     */
    protected function onContentItem()
    {
        $this->onError();
    }

    /**
     * Process a valid tool proxy registration request
     *
     * @return boolean True if no error
     */
    protected function onRegister()
    {
        $this->onError();
    }

    /**
     * Process a response to an invalid request
     *
     * @return boolean True if no further error processing required
     */
    protected function onError()
    {
        // only return error status
        return $this->ok;
    }

    ###
    ###    PRIVATE METHODS
    ###

    /**
     * Call any callback function for the requested action.
     *
     * This function may set the redirect_url and output properties.
     *
     * @return boolean True if no error reported
     */
    private function doCallback($method = null)
    {
        $callback = $method;
        if (is_null($callback)) {
            $callback = self::$METHOD_NAMES[$_POST['lti_message_type']];
        }
        if (method_exists($this, $callback)) {
            $result = $this->$callback();
        } elseif (is_null($method) && $this->ok) {
            $this->ok = false;
            $this->reason = "Message type not supported: {$_POST['lti_message_type']}";
        }
        if ($this->ok && ($_POST['lti_message_type'] == 'ToolProxyRegistrationRequest')) {
            $this->consumer->save();
        }
    }

    /**
     * Perform the result of an action.
     *
     * This function may redirect the user to another URL rather than returning a value.
     *
     * @return string Output to be displayed (redirection, or display HTML or message)
     */
    private function result()
    {
        $ok = false;
        if (!$this->ok) {
            $ok = $this->onError();
        }
        if (!$ok) {
            if (!$this->ok) {
                // If not valid, return an error message to the tool consumer if a return URL is provided
                if (!empty($this->returnUrl)) {
                    $errorUrl = $this->returnUrl;
                    if (strpos($errorUrl, '?') === false) {
                        $errorUrl .= '?';
                    } else {
                        $errorUrl .= '&';
                    }
                    if ($this->debugMode && !is_null($this->reason)) {
                        $errorUrl .= 'lti_errormsg=' . urlencode("Debug error: $this->reason");
                    } else {
                        $errorUrl .= 'lti_errormsg=' . urlencode($this->message);
                        if (!is_null($this->reason)) {
                            $errorUrl .= '&lti_errorlog=' . urlencode("Debug error: $this->reason");
                        }
                    }
                    if (!is_null($this->consumer) && isset($_POST['lti_message_type']) && ($_POST['lti_message_type'] === 'ContentItemSelectionRequest')) {
                        $formParams = array();
                        if (isset($_POST['data'])) {
                            $formParams['data'] = $_POST['data'];
                        }
                        $version = (isset($_POST['lti_version'])) ? $_POST['lti_version'] : self::LTI_VERSION1;
                        $formParams = $this->consumer->signParameters($errorUrl, 'ContentItemSelection', $version, $formParams);
                        $page = self::sendForm($errorUrl, $formParams);
                        echo $page;
                    } else {
                        header("Location: {$errorUrl}");
                    }
                    exit;
                } else {
                    if (!is_null($this->errorOutput)) {
                        echo $this->errorOutput;
                    } elseif ($this->debugMode && !empty($this->reason)) {
                        echo "Debug error: {$this->reason}";
                    } else {
                        echo "Error: {$this->message}";
                    }
                }
            } elseif (!is_null($this->redirectUrl)) {
                header("Location: {$this->redirectUrl}");
                exit;
            } elseif (!is_null($this->output)) {
                echo $this->output;
            }
        }
    }

    /**
     * Check the authenticity of the LTI launch request.
     *
     * The consumer, resource link and user objects will be initialised if the request is valid.
     *
     * @return boolean True if the request has been successfully validated.
     */
    private function authenticate()
    {

        // Get the consumer
        $doSaveConsumer = false;
        // Check all required launch parameters
        $this->ok = isset($_POST['lti_message_type']) && array_key_exists($_POST['lti_message_type'], self::$MESSAGE_TYPES);
        if (!$this->ok) {
            $this->reason = 'Invalid or missing lti_message_type parameter.';
        }
        if ($this->ok) {
            $this->ok = isset($_POST['lti_version']) && in_array($_POST['lti_version'], self::$LTI_VERSIONS);
            if (!$this->ok) {
                $this->reason = 'Invalid or missing lti_version parameter.';
            }
        }
        if ($this->ok) {
            if ($_POST['lti_message_type'] === 'basic-lti-launch-request') {
                $this->ok = isset($_POST['resource_link_id']) && (strlen(trim($_POST['resource_link_id'])) > 0);
                if (!$this->ok) {
                    $this->reason = 'Missing resource link ID.';
                }
            } elseif ($_POST['lti_message_type'] === 'ContentItemSelectionRequest') {
                if (isset($_POST['accept_media_types']) && (strlen(trim($_POST['accept_media_types'])) > 0)) {
                    $mediaTypes = array_filter(explode(',', str_replace(' ', '', $_POST['accept_media_types'])), 'strlen');
                    $mediaTypes = array_unique($mediaTypes);
                    $this->ok = count($mediaTypes) > 0;
                    if (!$this->ok) {
                        $this->reason = 'No accept_media_types found.';
                    } else {
                        $this->mediaTypes = $mediaTypes;
                    }
                } else {
                    $this->ok = false;
                }
                if ($this->ok && isset($_POST['accept_presentation_document_targets']) && (strlen(trim($_POST['accept_presentation_document_targets'])) > 0)) {
                    $documentTargets = array_filter(explode(',', str_replace(' ', '', $_POST['accept_presentation_document_targets'])), 'strlen');
                    $documentTargets = array_unique($documentTargets);
                    $this->ok = count($documentTargets) > 0;
                    if (!$this->ok) {
                        $this->reason = 'Missing or empty accept_presentation_document_targets parameter.';
                    } else {
                        foreach ($documentTargets as $documentTarget) {
                            $this->ok = $this->checkValue(
                                $documentTarget,
                                array('embed', 'frame', 'iframe', 'window', 'popup', 'overlay', 'none'),
                                'Invalid value in accept_presentation_document_targets parameter: %s.'
                            );
                            if (!$this->ok) {
                                break;
                            }
                        }
                        if ($this->ok) {
                            $this->documentTargets = $documentTargets;
                        }
                    }
                } else {
                    $this->ok = false;
                }
                if ($this->ok) {
                    $this->ok = isset($_POST['content_item_return_url']) && (strlen(trim($_POST['content_item_return_url'])) > 0);
                    if (!$this->ok) {
                        $this->reason = 'Missing content_item_return_url parameter.';
                    }
                }
            } elseif ($_POST['lti_message_type'] == 'ToolProxyRegistrationRequest') {
                $this->ok = ((isset($_POST['reg_key']) && (strlen(trim($_POST['reg_key'])) > 0)) &&
                             (isset($_POST['reg_password']) && (strlen(trim($_POST['reg_password'])) > 0)) &&
                             (isset($_POST['tc_profile_url']) && (strlen(trim($_POST['tc_profile_url'])) > 0)) &&
                             (isset($_POST['launch_presentation_return_url']) && (strlen(trim($_POST['launch_presentation_return_url'])) > 0)));
                if ($this->debugMode && !$this->ok) {
                    $this->reason = 'Missing message parameters.';
                }
            }
        }
        $now = time();
        // Check consumer key
        if ($this->ok && ($_POST['lti_message_type'] != 'ToolProxyRegistrationRequest')) {
            $this->ok = isset($_POST['oauth_consumer_key']);
            if (!$this->ok) {
                $this->reason = 'Missing consumer key.';
            }
            if ($this->ok) {
                $this->consumer = new ilLTIToolConsumer($_POST['oauth_consumer_key'], $this->dataConnector);
                $this->ok = !is_null($this->consumer->created);
                if (!$this->ok) {
                    $this->reason = 'Invalid consumer key.';
                }
            }
            if ($this->ok) {
                $today = date('Y-m-d', $now);
                if (is_null($this->consumer->lastAccess)) {
                    $doSaveConsumer = true;
                } else {
                    $last = date('Y-m-d', $this->consumer->lastAccess);
                    $doSaveConsumer = $doSaveConsumer || ($last !== $today);
                }
                $this->consumer->last_access = $now;
                try {
                    $store = new ceLTIc\LTI\OAuthDataStore($this);
                    $server = new ceLTIc\LTI\OAuth\OAuthServer($store);
                    $method = new ceLTIc\LTI\OAuth\OAuthSignatureMethod_HMAC_SHA1();
                    $server->add_signature_method($method);
                    $request = self::from_request();
                    $res = $server->verify_request($request);
                } catch (\Exception $e) {
                    $this->ok = false;
                    if (empty($this->reason)) {
                        if ($this->debugMode) {
                            $consumer = new OAuth\OAuthConsumer($this->consumer->getKey(), $this->consumer->secret);
                            $signature = $request->build_signature($method, $consumer, false);
                            $this->reason = $e->getMessage();
                            if (empty($this->reason)) {
                                $this->reason = 'OAuth exception';
                            }
                            $this->details[] = 'Timestamp: ' . time();
                            $this->details[] = 'consumer->getKey(): ' . $this->consumer->getKey();
                            $this->details[] = "Signature: {$signature}";
                            $this->details[] = "Base string: {$request->base_string}]";
                            $this->logger->dump($this->details);
                        } else {
                            $this->reason = 'OAuth signature check failed - perhaps an incorrect secret or timestamp.';
                        }
                    }
                }
            }

            if ($this->ok) {
                $today = date('Y-m-d', $now);
                if (is_null($this->consumer->lastAccess)) {
                    $doSaveConsumer = true;
                } else {
                    $last = date('Y-m-d', $this->consumer->lastAccess);
                    $doSaveConsumer = $doSaveConsumer || ($last !== $today);
                }
                $this->consumer->last_access = $now;
                if ($this->consumer->protected) {
                    if (!is_null($this->consumer->consumerGuid)) {
                        $this->ok = empty($_POST['tool_consumer_instance_guid']) ||
                             ($this->consumer->consumerGuid === $_POST['tool_consumer_instance_guid']);
                        if (!$this->ok) {
                            $this->reason = 'Request is from an invalid tool consumer.';
                        }
                    } else {
                        $this->ok = isset($_POST['tool_consumer_instance_guid']);
                        if (!$this->ok) {
                            $this->reason = 'A tool consumer GUID must be included in the launch request.';
                        }
                    }
                }
                if ($this->ok) {
                    $this->ok = $this->consumer->enabled;
                    if (!$this->ok) {
                        $this->reason = 'Tool consumer has not been enabled by the tool provider.';
                    }
                }
                if ($this->ok) {
                    $this->ok = is_null($this->consumer->enableFrom) || ($this->consumer->enableFrom <= $now);
                    if ($this->ok) {
                        $this->ok = is_null($this->consumer->enableUntil) || ($this->consumer->enableUntil > $now);
                        if (!$this->ok) {
                            $this->reason = 'Tool consumer access has expired.';
                        }
                    } else {
                        $this->reason = 'Tool consumer access is not yet available.';
                    }
                }
            }
            // Validate other message parameter values
            if ($this->ok) {
                if ($_POST['lti_message_type'] === 'ContentItemSelectionRequest') {
                    if (isset($_POST['accept_unsigned'])) {
                        $this->ok = $this->checkValue($_POST['accept_unsigned'], array('true', 'false'), 'Invalid value for accept_unsigned parameter: %s.');
                    }
                    if ($this->ok && isset($_POST['accept_multiple'])) {
                        $this->ok = $this->checkValue($_POST['accept_multiple'], array('true', 'false'), 'Invalid value for accept_multiple parameter: %s.');
                    }
                    if ($this->ok && isset($_POST['accept_copy_advice'])) {
                        $this->ok = $this->checkValue($_POST['accept_copy_advice'], array('true', 'false'), 'Invalid value for accept_copy_advice parameter: %s.');
                    }
                    if ($this->ok && isset($_POST['auto_create'])) {
                        $this->ok = $this->checkValue($_POST['auto_create'], array('true', 'false'), 'Invalid value for auto_create parameter: %s.');
                    }
                    if ($this->ok && isset($_POST['can_confirm'])) {
                        $this->ok = $this->checkValue($_POST['can_confirm'], array('true', 'false'), 'Invalid value for can_confirm parameter: %s.');
                    }
                } elseif (isset($_POST['launch_presentation_document_target'])) {
                    $this->ok = $this->checkValue(
                        $_POST['launch_presentation_document_target'],
                        array('embed', 'frame', 'iframe', 'window', 'popup', 'overlay'),
                        'Invalid value for launch_presentation_document_target parameter: %s.'
                    );
                }
            }
        }

        if ($this->ok && ($_POST['lti_message_type'] === 'ToolProxyRegistrationRequest')) {
            $this->ok = $_POST['lti_version'] == self::LTI_VERSION2;
            if (!$this->ok) {
                $this->reason = 'Invalid lti_version parameter';
            }
            if ($this->ok) {
                $http = new \ceLTIc\LTI\Http\HTTPMessage($_POST['tc_profile_url'], 'GET', null, 'Accept: application/vnd.ims.lti.v2.toolconsumerprofile+json');
                $this->ok = $http->send();
                if (!$this->ok) {
                    $this->reason = 'Tool consumer profile not accessible.';
                } else {
                    $tcProfile = json_decode($http->response);
                    $this->ok = !is_null($tcProfile);
                    if (!$this->ok) {
                        $this->reason = 'Invalid JSON in tool consumer profile.';
                    }
                }
            }
            // Check for required capabilities
            if ($this->ok) {
                $this->consumer = new ilLTIToolConsumer($_POST['oauth_consumer_key'], $this->dataConnector);
                $this->consumer->profile = $tcProfile;
                $capabilities = $this->consumer->profile->capability_offered;
                $missing = array();
                foreach ($this->resourceHandlers as $resourceHandler) {
                    foreach ($resourceHandler->requiredMessages as $message) {
                        if (!in_array($message->type, $capabilities)) {
                            $missing[$message->type] = true;
                        }
                    }
                }
                foreach ($this->constraints as $name => $constraint) {
                    if ($constraint['required']) {
                        if (!in_array($name, $capabilities) && !in_array($name, array_flip($capabilities))) {
                            $missing[$name] = true;
                        }
                    }
                }
                if (!empty($missing)) {
                    ksort($missing);
                    $this->reason = 'Required capability not offered - \'' . implode('\', \'', array_keys($missing)) . '\'';
                    $this->ok = false;
                }
            }
            // Check for required services
            if ($this->ok) {
                foreach ($this->requiredServices as $service) {
                    foreach ($service->formats as $format) {
                        if (!$this->findService($format, $service->actions)) {
                            if ($this->ok) {
                                $this->reason = 'Required service(s) not offered - ';
                                $this->ok = false;
                            } else {
                                $this->reason .= ', ';
                            }
                            $this->reason .= "'{$format}' [" . implode(', ', $service->actions) . ']';
                        }
                    }
                }
            }
            if ($this->ok) {
                if ($_POST['lti_message_type'] === 'ToolProxyRegistrationRequest') {
                    $this->consumer->profile = $tcProfile;
                    $this->consumer->secret = $_POST['reg_password'];
                    $this->consumer->ltiVersion = $_POST['lti_version'];
                    $this->consumer->name = $tcProfile->product_instance->service_owner->service_owner_name->default_value;
                    $this->consumer->consumerName = $this->consumer->name;
                    $this->consumer->consumerVersion = "{$tcProfile->product_instance->product_info->product_family->code}-{$tcProfile->product_instance->product_info->product_version}";
                    $this->consumer->consumerGuid = $tcProfile->product_instance->guid;
                    $this->consumer->enabled = true;
                    $this->consumer->protected = true;
                    $doSaveConsumer = true;
                }
            }
        } elseif ($this->ok && !empty($_POST['custom_tc_profile_url']) && empty($this->consumer->profile)) {
            $http = new \ceLTIc\LTI\Http\HTTPMessage($_POST['custom_tc_profile_url'], 'GET', null, 'Accept: application/vnd.ims.lti.v2.toolconsumerprofile+json');
            if ($http->send()) {
                $tcProfile = json_decode($http->response);
                if (!is_null($tcProfile)) {
                    $this->consumer->profile = $tcProfile;
                    $doSaveConsumer = true;
                }
            }
        }

        $this->logger->debug('Still ok: ' . ($this->ok ? '1' : '0'));
        if (!$this->ok) {
            $this->logger->debug('Reason: ' . $this->reason);
        }

        if ($this->ok) {

            // Set the request context
            if (isset($_POST['context_id'])) {
                $this->context = Context::fromConsumer($this->consumer, trim($_POST['context_id']));
                $title = '';
                if (isset($_POST['context_title'])) {
                    $title = trim($_POST['context_title']);
                }
                if (empty($title)) {
                    $title = "Course {$this->context->getId()}";
                }
                $this->context->title = $title;
            }

            // Set the request resource link
            if (isset($_POST['resource_link_id'])) {
                $contentItemId = '';
                if (isset($_POST['custom_content_item_id'])) {
                    $contentItemId = $_POST['custom_content_item_id'];
                }
                $this->resourceLink = ResourceLink::fromConsumer($this->consumer, trim($_POST['resource_link_id']), $contentItemId);
                if (!empty($this->context)) {
                    $this->resourceLink->setContextId($this->context->getRecordId());
                }
                $title = '';
                if (isset($_POST['resource_link_title'])) {
                    $title = trim($_POST['resource_link_title']);
                }
                if (empty($title)) {
                    $title = "Resource {$this->resourceLink->getId()}";
                }
                $this->resourceLink->title = $title;
                // Delete any existing custom parameters
                foreach ($this->consumer->getSettings() as $name => $value) {
                    if (strpos($name, 'custom_') === 0) {
                        $this->consumer->setSetting($name);
                        $doSaveConsumer = true;
                    }
                }
                if (!empty($this->context)) {
                    foreach ($this->context->getSettings() as $name => $value) {
                        if (strpos($name, 'custom_') === 0) {
                            $this->context->setSetting($name);
                        }
                    }
                }
                foreach ($this->resourceLink->getSettings() as $name => $value) {
                    if (strpos($name, 'custom_') === 0) {
                        $this->resourceLink->setSetting($name);
                    }
                }
                // Save LTI parameters
                foreach (self::$LTI_CONSUMER_SETTING_NAMES as $name) {
                    if (isset($_POST[$name])) {
                        $this->consumer->setSetting($name, $_POST[$name]);
                    } else {
                        $this->consumer->setSetting($name);
                    }
                }
                if (!empty($this->context)) {
                    foreach (self::$LTI_CONTEXT_SETTING_NAMES as $name) {
                        if (isset($_POST[$name])) {
                            $this->context->setSetting($name, $_POST[$name]);
                        } else {
                            $this->context->setSetting($name);
                        }
                    }
                }
                foreach (self::$LTI_RESOURCE_LINK_SETTING_NAMES as $name) {
                    if (isset($_POST[$name])) {
                        $this->resourceLink->setSetting($name, $_POST[$name]);
                    } else {
                        $this->resourceLink->setSetting($name);
                    }
                }
                // Save other custom parameters
                foreach ($_POST as $name => $value) {
                    if ((strpos($name, 'custom_') === 0) &&
                        !in_array($name, array_merge(self::$LTI_CONSUMER_SETTING_NAMES, self::$LTI_CONTEXT_SETTING_NAMES, self::$LTI_RESOURCE_LINK_SETTING_NAMES))) {
                        $this->resourceLink->setSetting($name, $value);
                    }
                }
            }

            // Set the user instance
            $userId = '';
            if (isset($_POST['user_id'])) {
                $userId = trim($_POST['user_id']);
            }

            $this->user = ilLTIUser::init()->fromResourceLink($this->resourceLink, $userId);

            // Set the user name
            $firstname = (isset($_POST['lis_person_name_given'])) ? $_POST['lis_person_name_given'] : '';
            $lastname = (isset($_POST['lis_person_name_family'])) ? $_POST['lis_person_name_family'] : '';
            $fullname = (isset($_POST['lis_person_name_full'])) ? $_POST['lis_person_name_full'] : '';
            $this->user->setNames($firstname, $lastname, $fullname);

            // Set the user email
            $email = (isset($_POST['lis_person_contact_email_primary'])) ? $_POST['lis_person_contact_email_primary'] : '';
            $this->user->setEmail($email, $this->defaultEmail);

            // Set the user image URI
            if (isset($_POST['user_image'])) {
                $this->user->image = $_POST['user_image'];
            }

            // Set the user roles
            if (isset($_POST['roles'])) {
                $this->user->roles = self::parseRoles($_POST['roles']);
            }

            // Initialise the consumer and check for changes
            $this->consumer->defaultEmail = $this->defaultEmail;
            if ($this->consumer->ltiVersion !== $_POST['lti_version']) {
                $this->consumer->ltiVersion = $_POST['lti_version'];
                $doSaveConsumer = true;
            }
            if (isset($_POST['tool_consumer_instance_name'])) {
                if ($this->consumer->consumerName !== $_POST['tool_consumer_instance_name']) {
                    $this->consumer->consumerName = $_POST['tool_consumer_instance_name'];
                    $doSaveConsumer = true;
                }
            }
            if (isset($_POST['tool_consumer_info_product_family_code'])) {
                $version = $_POST['tool_consumer_info_product_family_code'];
                if (isset($_POST['tool_consumer_info_version'])) {
                    $version .= "-{$_POST['tool_consumer_info_version']}";
                }
                // do not delete any existing consumer version if none is passed
                if ($this->consumer->consumerVersion !== $version) {
                    $this->consumer->consumerVersion = $version;
                    $doSaveConsumer = true;
                }
            } elseif (isset($_POST['ext_lms']) && ($this->consumer->consumerName !== $_POST['ext_lms'])) {
                $this->consumer->consumerVersion = $_POST['ext_lms'];
                $doSaveConsumer = true;
            }
            if (isset($_POST['tool_consumer_instance_guid'])) {
                if (is_null($this->consumer->consumerGuid)) {
                    $this->consumer->consumerGuid = $_POST['tool_consumer_instance_guid'];
                    $doSaveConsumer = true;
                } elseif (!$this->consumer->protected) {
                    $doSaveConsumer = ($this->consumer->consumerGuid !== $_POST['tool_consumer_instance_guid']);
                    if ($doSaveConsumer) {
                        $this->consumer->consumerGuid = $_POST['tool_consumer_instance_guid'];
                    }
                }
            }
            if (isset($_POST['launch_presentation_css_url'])) {
                if ($this->consumer->cssPath !== $_POST['launch_presentation_css_url']) {
                    $this->consumer->cssPath = $_POST['launch_presentation_css_url'];
                    $doSaveConsumer = true;
                }
            } elseif (isset($_POST['ext_launch_presentation_css_url']) &&
                 ($this->consumer->cssPath !== $_POST['ext_launch_presentation_css_url'])) {
                $this->consumer->cssPath = $_POST['ext_launch_presentation_css_url'];
                $doSaveConsumer = true;
            } elseif (!empty($this->consumer->cssPath)) {
                $this->consumer->cssPath = null;
                $doSaveConsumer = true;
            }
        }

        // Persist changes to consumer
        if ($doSaveConsumer) {
            $this->consumer->save();
        }
        if ($this->ok && isset($this->context)) {
            $this->context->save();
        }

        if ($this->ok && isset($this->resourceLink)) {

            // Check if a share arrangement is in place for this resource link
            // Persist changes to resource link
            $this->resourceLink->save();

            // Save the user instance
            if (isset($_POST['lis_result_sourcedid'])) {
                if ($this->user->ltiResultSourcedId !== $_POST['lis_result_sourcedid']) {
                    $this->user->ltiResultSourcedId = $_POST['lis_result_sourcedid'];
                    $this->user->save();
                }
            } elseif (!empty($this->user->ltiResultSourcedId)) {
                $this->user->ltiResultSourcedId = '';
                $this->user->save();
            }
        }
        return $this->ok;
    }

    /**
     * Check if a share arrangement is in place.
     *
     * @return boolean True if no error is reported
     */
    private function checkForShare()
    {
        $ok = true;
        $doSaveResourceLink = true;

        $id = $this->resourceLink->primaryResourceLinkId;

        $shareRequest = isset($_POST['custom_share_key']) && !empty($_POST['custom_share_key']);
        if ($shareRequest) {
            if (!$this->allowSharing) {
                $ok = false;
                $this->reason = 'Your sharing request has been refused because sharing is not being permitted.';
            } else {
                // Check if this is a new share key
                $shareKey = new ResourceLinkShareKey($this->resourceLink, $_POST['custom_share_key']);
                if (!is_null($shareKey->primaryConsumerKey) && !is_null($shareKey->primaryResourceLinkId)) {
                    // Update resource link with sharing primary resource link details
                    $key = $shareKey->primaryConsumerKey;
                    $id = $shareKey->primaryResourceLinkId;
                    $ok = ($key !== $this->consumer->getKey()) || ($id != $this->resourceLink->getId());
                    if ($ok) {
                        $this->resourceLink->primaryConsumerKey = $key;
                        $this->resourceLink->primaryResourceLinkId = $id;
                        $this->resourceLink->shareApproved = $shareKey->autoApprove;
                        $ok = $this->resourceLink->save();
                        if ($ok) {
                            $doSaveResourceLink = false;
                            $this->user->getResourceLink()->primaryConsumerKey = $key;
                            $this->user->getResourceLink()->primaryResourceLinkId = $id;
                            $this->user->getResourceLink()->shareApproved = $shareKey->autoApprove;
                            $this->user->getResourceLink()->updated = time();
                            // Remove share key
                            $shareKey->delete();
                        } else {
                            $this->reason = 'An error occurred initialising your share arrangement.';
                        }
                    } else {
                        $this->reason = 'It is not possible to share your resource link with yourself.';
                    }
                }
                if ($ok) {
                    $ok = !is_null($key);
                    if (!$ok) {
                        $this->reason = 'You have requested to share a resource link but none is available.';
                    } else {
                        $ok = (!is_null($this->user->getResourceLink()->shareApproved) && $this->user->getResourceLink()->shareApproved);
                        if (!$ok) {
                            $this->reason = 'Your share request is waiting to be approved.';
                        }
                    }
                }
            }
        } else {
            // Check no share is in place
            $ok = is_null($id);
            if (!$ok) {
                $this->reason = 'You have not requested to share a resource link but an arrangement is currently in place.';
            }
        }
        // Look up primary resource link
        if ($ok && !is_null($id)) {
            // $consumer = new ToolConsumer($key, $this->dataConnector);
            $consumer = new ilLTIToolConsumer($_POST['oauth_consumer_key'], $this->dataConnector);
            $ok = !is_null($consumer->created);
            if ($ok) {
                $resourceLink = ResourceLink::fromConsumer($consumer, $id);
                $ok = !is_null($resourceLink->created);
            }
            if ($ok) {
                if ($doSaveResourceLink) {
                    $this->resourceLink->save();
                }
                $this->resourceLink = $resourceLink;
            } else {
                $this->reason = 'Unable to load resource link being shared.';
            }
        }

        return $ok;
    }
    /**
     * Validate a parameter value from an array of permitted values.
     *
     * @return boolean True if value is valid
     */
    private function checkValue($value, $values, $reason)
    {
        $ok = in_array($value, $values);
        if (!$ok && !empty($reason)) {
            $this->reason = sprintf($reason, $value);
        }

        return $ok;
    }

    public static function from_request($http_method = null, $http_url = null, $parameters = null)
    {
        if (!$http_url) {
            if ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ||
                (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && ($_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')) ||
                (isset($_SERVER['HTTP_X_URL_SCHEME']) && ($_SERVER['HTTP_X_URL_SCHEME'] === 'https'))) {
                $_SERVER['HTTPS'] = 'on';
                $_SERVER['SERVER_PORT'] = 443;
            } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $_SERVER['HTTPS'] = 'off';
                $_SERVER['SERVER_PORT'] = 80;
            } elseif (!isset($_SERVER['HTTPS'])) {
                $_SERVER['HTTPS'] = 'off';
            }

            if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $host = explode(':', $_SERVER['HTTP_X_FORWARDED_HOST'], 2);
                $_SERVER['SERVER_NAME'] = $host[0];
                if (count($host) > 1) {
                    $_SERVER['SERVER_PORT'] = $host[1];
                } elseif ($_SERVER['HTTPS'] === 'on') {
                    $_SERVER['SERVER_PORT'] = 443;
                } else {
                    $_SERVER['SERVER_PORT'] = 80;
                }
            }
            $scheme = ($_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $http_url = "{$scheme}://{$_SERVER['SERVER_NAME']}:{$_SERVER['SERVER_PORT']}{$_SERVER['REQUEST_URI']}";
            # $http_url = "{$scheme}://{$_SERVER['HTTP_HOST']}:{$_SERVER['SERVER_PORT']}{$_SERVER['REQUEST_URI']}";
        }
        $http_method = ($http_method) ? $http_method : $_SERVER['REQUEST_METHOD'];

        // We weren't handed any parameters, so let's find the ones relevant to
        // this request.
        // If you run XML-RPC or similar you should use this to provide your own
        // parsed parameter-list
        if (!$parameters) {
            // Find request headers
            $request_headers = OAuthUtil::get_headers();

            // Parse the query-string to find GET parameters
            if (isset($_SERVER['QUERY_STRING'])) {
                $parameters = OAuthUtil::parse_parameters($_SERVER['QUERY_STRING']);
            } else {
                $parameters = array();
            }

            if (
                (
                    $http_method === 'POST' &&
                    isset($request_headers['Content-Type']) &&
                    stristr($request_headers['Content-Type'], 'application/x-www-form-urlencoded')
                ) ||
                !empty($_POST)) {
                // It's a POST request of the proper content-type, so parse POST
                // parameters and add those overriding any duplicates from GET
                $post_data = OAuthUtil::parse_parameters(file_get_contents(OAuthRequest::$POST_INPUT));
                $parameters = array_replace_recursive($parameters, $post_data);
            }

            // We have a Authorization-header with OAuth data. Parse the header
            // and add those overriding any duplicates from GET or POST
            if (isset($request_headers['Authorization']) && substr($request_headers['Authorization'], 0, 6) == 'OAuth ') {
                $header_parameters = OAuthUtil::split_header($request_headers['Authorization']);
                $parameters = array_merge_recursive($parameters, $header_parameters);
            }
        }

        return new OAuthRequest($http_method, $http_url, $parameters);
    }


}
