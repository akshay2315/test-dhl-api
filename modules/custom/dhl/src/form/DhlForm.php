<?php
namespace Drupal\dhl\form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class DhlForm extends FormBase{

  protected $httpClient;

  public function __construct(ClientInterface $http_client)
  {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client')
    );
  }

  public function getFormId()
  {
    return 'dhlf';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['country'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Country'),
    ];
    $form['city'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' =>  $this->t('City'),
    ];
    $form['postal_code']=[
      '#type' =>  'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Postal Code'),
    ];

    $form['actions']['#type'] = 'actions';

    $form['actions']['submit']=[
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $country = $form_state->getValue('country');
    $city = $form_state->getValue('city');
    $postal_code = $form_state->getValue('postal_code');

    if (!preg_match('/^[a-zA-Z]*$/', $country))
    {
      $form_state->setErrorByName('country', $this->t("Please enter a valid country name."));
    }
    if (!preg_match('/^[a-zA-Z]*$/', $city))
    {
      $form_state->setErrorByName('city', $this->t("Please enter a valid city name."));
    }
    if (!is_numeric($postal_code))
    {
      $form_state->setErrorByName('postal_code', $this->t("Please enter a valid postal code."));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $countryCode = $form_state->getValue('country');
    $streetAddress = $form_state->getValue('city');
    $postalCode = $form_state->getValue('postal_code');

    $api_url = 'https://api-sandbox.dhl.com/location-finder/v1/find-by-address';

    $headers = [
      'DHL-API-Key' => 'sCH6YMqzutwMb1zwdFsAwhpjG8opTvAY',
    ];

    $query_params = [
      'countryCode' => $countryCode,
      'postalCode' => $postalCode,
      'streetAddress' => $streetAddress,
    ];

    try
    {
      $response = $this->httpClient->request('GET', $api_url, [
        'headers' => $headers,
        'query' => $query_params,
      ]);

      if ($response->getStatusCode() == Response::HTTP_OK)
      {
        $locations = json_decode($response->getBody()->getContents());

        $dat_array = $this->filterLocations($locations);

        foreach ($dat_array as $key => $locationData)
        {
          $timestamp = time();
          $fileName = "data_location_$key" . "_$timestamp.yaml";
          $filePath = "D:/xampp/htdocs/dhl/$fileName";

          $yaml = Yaml::dump($locationData);

          file_put_contents($filePath, $yaml);

          if (file_exists($filePath)) {
            \Drupal::messenger()->addStatus($this->t('Data saved to %file_path', ['%file_path' => $filePath]));
          } else {
            \Drupal::messenger()->addError($this->t('Failed to save data to %file_path', ['%file_path' => $filePath]));
          }
        }
      }else
      {
        \Drupal::messenger()->addError($this->t('Error: Unable to fetch data from the DHL API.'));
      }
    } catch (\Exception $e)
    {
      \Drupal::logger('dhl')->error('An error occurred while processing the DHL API request: @error', ['@error' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('An error occurred while processing your request.'));
    }
  }
    public function filterLocations($locations)
    {

      $filteredData = [];
      $formattedOpeningHours = [];
      $formattedOpeningHoursNew = [];

      $data_array = [];

      foreach($locations as $key=>$location)
      {
        foreach($location as $lkey=>$lval)
        {

          $location_name = $lval->name;

          $address_name = $lval->place->address->streetAddress;

          $digit = explode(" ",$address_name);
          if(preg_match('/\d+/', $address_name, $matches)){
            $integerValue = $matches[0];

            if($integerValue % 2  !== 0){
              continue;
            }

          }

          $data_array[$lkey]['location_name'] = $location_name;

          $address = [
            'countryCode' => $lval->place->address->countryCode,
            'postalCode' => $lval->place->address->postalCode,
            'addressLocality' => $lval->place->address->addressLocality,
            'streetAddress' => $lval->place->address->streetAddress,
        ];
          $data_array[$lkey]['address'] = $address;

          $opening_hours = $lval->openingHours;
        }
      }

      foreach($location as $loc_key=>$loc_val)
      {
        $location_n = $loc_val->place->address->streetAddress;
        $digit = explode(" ",$location_n);
          if(preg_match('/\d+/', $location_n, $matches)){
            $integerValue = $matches[0];

            if($integerValue % 2  !== 0){
              continue;
            }

          }

        $opening_hours = $locations->locations[$loc_key]->openingHours;


        $hasWorked = false;

          foreach($opening_hours as $opening_hour)
        {
          $dayOfWeek = pathinfo($opening_hour->dayOfWeek, PATHINFO_FILENAME);
          $formattedOpeningHours = "{$opening_hour->opens} - {$opening_hour->closes}";
          $data_array[$loc_key]['Opening_Hours'][$dayOfWeek] = $formattedOpeningHours;

          $daysOfWeekArray[] = $dayOfWeek;

          if($formattedOpeningHours !== "00:00:00 - 00:00:00")
          {
            $hasWorked= true;
          }
        }
        $locationHasPublicHolidays = (in_array('Saturday', $daysOfWeekArray) || in_array('Sunday', $daysOfWeekArray) || in_array('PublicHolidays', $daysOfWeekArray));
      }

      if (!$hasWorked && $locationHasPublicHolidays)
      {
        unset($data_array[$loc_key]);
      }
    return $data_array;

    }
  }


?>
