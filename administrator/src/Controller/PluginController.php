<?php

namespace Alfa\Component\Alfa\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
// use Joomla\CMS\Factory;
use Joomla\CMS\Response\JsonResponse;

// use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;

\defined('_JEXEC') or die;

class PluginController extends BaseController
{
    // protected $default_view = 'plugin'; // Force Joomla to recognize the name

    /**
     * Method to display a view.
     *
     * @param   boolean  $cachable   If true, the view output will be cached
     * @param   array    $urlparams  An array of safe URL parameters and their variable types, for valid values see {@link InputFilter::clean()}.
     *
     * @return  BaseController|boolean  This object to support chaining.
     *
     * @since   1.0.1
     */
    // public function display($cachable = false, $urlparams = array())
    // {
    //     return parent::display();
    // }


    public function trigger()
    {

        $app = $this->app;
        $input = $app->input;

        // $response = '{}';
        $response_error = false;
        $response_data = null;
        $response_result = null;
        $response_message = '';

        $type = $input->getString('type', '');
        $name = $input->getString('name', '');
        $func = $input->getString('func', '');
        $format = $input->getString('format', '');

        try {
            // Get and validate required parameters
            $requiredParams = [
                'type' => $type,
                'name' => $name,
                'func' => $func,
            ];

            // Check for empty required parameters
            $missingParams = array_filter($requiredParams, function ($value) {
                return empty($value);
            });

            if (!empty($missingParams)) {
                throw new \Exception(
                    'Missing required parameters: ' . implode(', ', array_keys($missingParams)),
                    400
                );
            }

            // Boot the plugin
            $plugin = $app->bootPlugin($requiredParams['name'], $requiredParams['type']);
            // var_dump($plugin); exit;
            // if (!$plugin) {
            //     throw new \Exception(
            //         sprintf('Plugin %s of type %s not found', $requiredParams['name'], $requiredParams['type']),
            //         404
            //     );
            // }

            // Check if the method exists and is callable
            if (!method_exists($plugin, $requiredParams['func']) || !is_callable([$plugin, $requiredParams['func']])) {
                throw new \Exception(
                    "Plugin type {$requiredParams['type']}, name {$requiredParams['name']} or Method {$requiredParams['func']} not found or method not callable in plugin",
                    405
                );
            }


            $response_result = $plugin->{$requiredParams['func']}();
            // alternative way to call the function
            // $response_result = call_user_func([$plugin, $requiredParams['func']]);

        } catch (\Exception $e) {
            // Log the error
            // $app->getLogger()->error($e->getMessage(), ['trace' => $e->getTrace()]);

            // $app->enqueueMessage($e->getMessage(), 'error');
            // $response_error = true;

            echo new JsonResponse(null, $e->getMessage(), true);

        }


        if ($format == 'json') {

            if (json_validate($response_result)) {
                $response = $response_result;
            } else {
                // throw new \Exception("Invalid JSON response received.");
                // $app->enqueueMessage('Invalid JSON response received', 'error');
                echo new JsonResponse($response_result, 'Invalid JSON response received', true);
                $this->app->close();
            }

        } else {
            $response = new JsonResponse($response_result, $response_message, $response_error);

        }

        echo $response;

        $this->app->close();

    }

}
