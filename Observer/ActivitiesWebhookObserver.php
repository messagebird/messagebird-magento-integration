<?php

namespace Messagebird\Observer;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

class ActivitiesWebhookObserver implements ObserverInterface
{
    /*
     * Please change these values to the configuration shown on setup!
     * -------------------------------------------------------------------------
     */

    public const INTEGRATION_USER = 'your_login';
    public const INTEGRATION_PASSWORD = 'your_password';

    /* ------------------------------------------------------------------------ */


    private const WEBHOOK_URL = 'https://activities.messagebird.com/webhook/magento';

    private $logger;
    private $storeManager;
    private $orderRepository;
    private $httpClient;

    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        ClientInterface $httpClient = null
    ) {
        $this->logger             = $logger;
        $this->storeManager       = $storeManager;
        $this->orderRepository    = $orderRepository;
        $this->httpClient         = $httpClient ?? new Client();
    }

    public function execute(Observer $observer)
    {
        $this->logger->debug('MessageBird integration: Starting.');

        try {
            /** @var OrderInterface $orderFromEvent */
            $orderFromEvent = $observer->getEvent()->getOrder();

            if ($orderFromEvent === null) {
                $this->logger->debug('MessageBird integration: Event did not contain order.');
                return;
            }

            $order = $this->orderRepository->get($orderFromEvent->getId());

            $billingAddress  = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();

            $data = [
                'order_id' => $order->getId(),
                'payload'  => [
                    'order'           => $orderFromEvent->getData(),
                    'customer'        => $this->serializeCustomer($order),
                    'billingaddress'  => $this->serializeAddress($billingAddress),
                    'shippingaddress' => $this->serializeAddress($shippingAddress),
                ],
            ];

            $this->callWebhook($data);
        } catch (\Exception $e) {
            $this->logError(
                'An exception occurred during execution.',
                [
                    'message'   => $e->getMessage(),
                    'traceback' => $e->getTraceAsString(),
                ]
            );
        }

        $this->logger->debug('MessageBird integration: Finished.');
    }

    private function callWebhook(array $data): void
    {
        try {
            $this->logger->debug('Messagebird integration: Posting webhook data', ['data' => $data]);

            $this->httpClient->post(self::WEBHOOK_URL, [
                RequestOptions::AUTH => [self::INTEGRATION_USER, self::INTEGRATION_PASSWORD],
                RequestOptions::JSON => $data,
                RequestOptions::HEADERS => [
                    'X-Magento-Webhook-Topic' => 'orders/created',
                    'X-Magento-Webhook-Source' => $this->getStoreHost(),
                ],
            ]);
        } catch (GuzzleException $exception) {
            $this->logError(
                'The webhook could not be sent.',
                [
                    'error'  => $exception->getMessage(),
                ]
            );
        } catch (\Exception $e) {
            $this->logError(
                'An exception occurred during execution.',
                [
                    'message'   => $e->getMessage(),
                    'traceback' => $e->getTraceAsString(),
                ]
            );
        }
    }

    private function logError(string $message, array $context): void
    {
        $this->logger->error(sprintf('MessageBird integration: %s', $message), $context);
    }

    private function serializeAddress(?OrderAddressInterface $billingAddress): ?array
    {
        if ($billingAddress === null) {
            return [];
        }

        return [
            'city'       => $billingAddress->getCity(),
            'street'     => $billingAddress->getStreet(),
            'postcode'   => $billingAddress->getPostcode(),
            'telephone'  => $billingAddress->getTelephone(),
            'regioncode' => $billingAddress->getRegionCode(),
        ];
    }

    private function serializeCustomer(OrderInterface $order): array
    {
        return [
            'id'        => $order->getCustomerId(),
            'lastname'  => $order->getCustomerLastname(),
            'firstname' => $order->getCustomerFirstname(),
            'email'     => $order->getCustomerEmail(),
        ];
    }

    private function getStoreHost(): string
    {
        $url = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        $uri = new Uri($url);

        return $uri->getHost();
    }
}
