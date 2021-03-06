<?php
namespace Piwik\Plugins\MobileMessaging\SMSProvider;

/**
 * IBM Bluemix(tm) Twilio Piwik SMSProvider Plugin
 *
 * filename: Twilio.php
 * @link https://www.bluemix.net
 * @license Apache 2.0
 * @author Sanjay Joshi <joshisa (at) us (dot) ibm (dot) com>
 * @copyright IBM 2014
 */
 
use Exception;
use Piwik\Http;
use Piwik\Plugins\MobileMessaging\APIException;
use Piwik\Plugins\MobileMessaging\SMSProvider;
use Piwik\Log;
use Services_Twilio;
 
require_once PIWIK_INCLUDE_PATH . "/libs/twilio-php-master/Services/Twilio.php";
require_once PIWIK_INCLUDE_PATH . "/plugins/MobileMessaging/APIException.php";
 

/**
 * Used to provide Twilio SMS Capability within Piwik
 *
 */
class Twilio extends SMSProvider
{
 
    // Read your Twilio AuthToken from www.twilio.com/user/account
    public function verifyCredential($apiKey)
    {
      $this->getCreditLeft($apiKey);
      return true;
    }
 
    /**
     * Send the SMS Text associated with this SMSProvider Library
     *
     * @throws Exception If the Twilio Library encounters an error
     * @param string $apiKey - Normalized Valid Twilio Incoming phone number in the form of "+{Country Code}{TwilioPhoneNumber}" (e.g. +19195551212, ...)
     * @param string $smsText - Text payload
     * @param string $phoneNumber - Valid phone number for receipt of text payload
     * @param string $from - Valid Twilio Incoming phone number in the form of "+{Country Code}{TwilioPhoneNumber}" (e.g. +19195551212, ...)
     * @return PiwikPluginsMobileMessagingSMSProvider
     */
    public function sendSMS($apiKey, $smsText, $phoneNumber, $from)
    {  
      try {
         if (!isset($_ENV["SMSACCOUNT"]) || !isset($_ENV["SMSTOKEN"])) {
            throw new APIException("IBM Bluemix Twilio Environment Variable not set.  Inspect bootstrap.php for environment variable parsing logic.");
         }
          
         $client = new Services_Twilio($_ENV["SMSACCOUNT"],$_ENV["SMSTOKEN"]);
          
         // Twilio forbids use of a $from number other than the Twilio Registered Number.  It is not possible to customize the Sender ID for SMS messages sent from Twilio.
         // To learn more, visit this link:  https://www.twilio.com/help/faq/sms/can-i-specify-the-phone-number-a-recipient-sees-when-getting-an-sms-from-my-twilio-app
         // Changing $from parameter to be validated Twilio Incoming Number provided by the user via the apiKey field.
         $from = $apiKey;
          
         $message = $client->account->messages->sendMessage(
          $from,
          $phoneNumber,
          $smsText);
      } catch (Services_Twilio_RestException $e) {
        throw new APIException('Twilio API returned the following error message: ' . $e->getMessage());
      }
    }
 
    /**
     * Function used to roughly complete account validation (e.g.  Enough credits, valid incoming number provided, etc ...)
     * Function name follows Clockwork SMS Provider class
     *
     * @throws Exception If the Twilio Library encounters an error
     * @param string $proposedFrom - Valid Twilio Incoming phone number in the form of "+{Country Code}{TwilioPhoneNumber}" (e.g. +19195551212, ...)
     * @return boolean
     */
    public function getCreditLeft($apiKey)
    {
         try {
              $incomingTwilio = $this->normalizeTwilioNumber($apiKey);
              Log::info('TwilioSMS: Cleansing user-provided Twilio Managed (FROM) Phone Number');
              $badchars = array("(", ")", "-", ".", " ");
              $incomingTwilioClean = str_replace($badchars, "", $incomingTwilio);

              if ($this->validateTwilioNumber($incomingTwilioClean)) {
                //"Bluemix Twilio Service Account verified using Incoming #".$number->phone_number;
                return  "Incoming Number (".$incomingTwilioClean.") was successfully validated. Happy Messaging!";
              } else {
                //"Bluemix Twilio Service Account verified using Incoming #".$number->phone_number;
                return  "Unable to verify (".$incomingTwilioClean.") as a valid managed number for the bound Bluemix Twilio Service Account";
              }
          } catch (Exception $e) {
                Log::info('Twilio Troubleshooting: Make sure that your Bluemix Service AccountSID and Token are correct');
                throw new APIException('Error during Twilio account validation ' . $e->getMessage());
                return false;
          }
    }
     
    /**
     * Validate user-provided Twilio Phone Number for source of SMS
     *
     * @throws Exception If the Twilio Library encounters an error
     * @param string $proposedFrom - Valid Twilio Incoming phone number in the form of "+{Country Code}{TwilioPhoneNumber}" (e.g. +19195551212, ...)
     * @return boolean
     */
     
    private function validateTwilioNumber($proposedFrom)
    {
        try {
            if (!isset($_ENV["SMSACCOUNT"]) || !isset($_ENV["SMSTOKEN"])) {
                throw new APIException("IBM Bluemix Twilio Environment Variable not set.  Inspect bootstrap.php for environment variable parsing logic.");
            }

            $client = new Services_Twilio($_ENV["SMSACCOUNT"],$_ENV["SMSTOKEN"]);
            Log::info('TwilioSMS: Enumerate through incoming_phone_numbers ...');
            foreach ($client->account->incoming_phone_numbers as $number) {
                 $cleanRegisteredIncomingNumber = $this->normalizeTwilioNumber($number->phone_number);
                 Log::info('Checking against incoming Number: '.$cleanRegisteredIncomingNumber);
                 if ($proposedFrom === $cleanRegisteredIncomingNumber) {
                   //Only return true if the phone number has been matched
                   return  true;
                 }
            }
            throw new APIException("Failed to validate user provided number as a valid Incoming Number within the bound IBM Bluemix Twilio Account.  Did you forget to include the Country Code associated with this number in your Twilio account? Rather than the Twilio Friendly Name, please provide the full phone number with country code in this form (+10123456789).");
        } catch (Services_Twilio_RestException $e) {
            throw new APIException('Twilio API returned the following error message: ' . $e->getMessage());
        }
    }
     
    /**
     * Validate user-provided Twilio Phone Number for source of SMS
     *
     * @throws Exception If the pre_match_all encounters an error
     * @param string $proposedFrom - User Provided Twilio Incoming phone number in unknown format
     * @return string in Twilio PHP Library expected form of "+{Country Code}{TwilioPhoneNumber}" (e.g. +19195551212, ...) 
     */
     
    private function normalizeTwilioNumber($proposedFrom)
    {
        try {
            if (preg_match('/^\+d+$/', $proposedFrom)) {
                return $proposedFrom;
            } else {
                // Credit to:  http://stackoverflow.com/questions/4708248/formatting-phone-numbers-in-php
                $normalized = preg_replace('~[^d]*(d{1,4})[^d]*(d{3})[^d]*(d{3})[^d]*(d{4}).*~', '+$1$2$3$4', $proposedFrom);
                return $normalized;
            }
        } catch (Exception $e) {
            throw new APIException('Error during normalization of Twilio Number: ' . $e->getMessage());
            return $proposedFrom;
        }
    }
}
