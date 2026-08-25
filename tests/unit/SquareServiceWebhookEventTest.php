<?php

namespace Nikolag\Square\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nikolag\Square\Builders\WebhookBuilder;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\WebhookEvent;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Tests\Traits\CreatesWebhookSubscription;

class SquareServiceWebhookEventTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebhookSubscription;

    /**
     * Test creating a webhook builder instance.
     */
    public function test_webhook_builder_creation(): void
    {
        $builder = Square::webhookBuilder();

        $this->assertInstanceOf(WebhookBuilder::class, $builder);
    }

    /**
     * Test marking a webhook event as processed.
     */
    public function test_mark_webhook_event_processed(): void
    {
        // Create required webhook subscription
        $subscription = $this->createTestWebhookSubscription();

        // Create a webhook event
        $event = WebhookEvent::create([
            'square_event_id'         => 'event_123',
            'event_type'              => 'order.created',
            'event_time'              => now(),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_PENDING,
            'webhook_subscription_id' => $subscription->id,
        ]);

        // Execute the test
        $result = Square::markWebhookEventProcessed('event_123');

        // Assertions
        $this->assertTrue($result);

        $event->refresh();
        $this->assertEquals(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNotNull($event->processed_at);
    }

    /**
     * Test marking a webhook event as failed.
     */
    public function test_mark_webhook_event_failed(): void
    {
        // Create required webhook subscription
        $subscription = $this->createTestWebhookSubscription();

        // Create a webhook event
        $event = WebhookEvent::create([
            'square_event_id'         => 'event_123',
            'event_type'              => 'order.created',
            'event_time'              => now(),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_PENDING,
            'webhook_subscription_id' => $subscription->id,
        ]);

        // Execute the test
        $result = Square::markWebhookEventFailed('event_123', 'Test error message');

        // Assertions
        $this->assertTrue($result);

        $event->refresh();
        $this->assertEquals(WebhookEvent::STATUS_FAILED, $event->status);
        $this->assertEquals('Test error message', $event->error_message);
        $this->assertNotNull($event->processed_at);
    }

    /**
     * Test that non-existent webhook event methods return false.
     */
    public function test_webhook_event_methods_with_non_existent_events(): void
    {
        // Test marking non-existent event as processed
        $result = Square::markWebhookEventProcessed('non_existent_event');
        $this->assertFalse($result);

        // Test marking non-existent event as failed
        $result = Square::markWebhookEventFailed('non_existent_event', 'Error message');
        $this->assertFalse($result);
    }

    /**
     * Test cleaning up old webhook events.
     */
    public function test_cleanup_old_webhook_events(): void
    {
        // Create required webhook subscription
        $subscription = $this->createTestWebhookSubscription();

        // Create test webhook events with different ages
        $oldEvent1 = new WebhookEvent([
            'square_event_id'         => 'old_event_1',
            'event_type'              => 'order.created',
            'event_time'              => now()->subDays(45),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_PROCESSED,
            'webhook_subscription_id' => $subscription->id,
        ]);
        $oldEvent1->created_at = now()->subDays(45);
        $oldEvent1->save();

        $oldEvent2 = new WebhookEvent([
            'square_event_id'         => 'old_event_2',
            'event_type'              => 'order.updated',
            'event_time'              => now()->subDays(35),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_FAILED,
            'webhook_subscription_id' => $subscription->id,
        ]);
        $oldEvent2->created_at = now()->subDays(35);
        $oldEvent2->save();

        $oldPendingEvent = new WebhookEvent([
            'square_event_id'         => 'old_pending_event',
            'event_type'              => 'order.created',
            'event_time'              => now()->subDays(40),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_PENDING,
            'webhook_subscription_id' => $subscription->id,
        ]);
        $oldPendingEvent->created_at = now()->subDays(40);
        $oldPendingEvent->save();

        $recentEvent = new WebhookEvent([
            'square_event_id'         => 'recent_event',
            'event_type'              => 'order.created',
            'event_time'              => now()->subDays(10),
            'event_data'              => ['test' => 'data'],
            'status'                  => WebhookEvent::STATUS_PROCESSED,
            'webhook_subscription_id' => $subscription->id,
        ]);
        $recentEvent->created_at = now()->subDays(10);
        $recentEvent->save();

        // Execute the test - cleanup events older than 30 days
        $deletedCount = Square::cleanupOldWebhookEvents(30);

        // Assertions - should delete old processed/failed events but not pending ones
        $this->assertEquals(2, $deletedCount);

        // Verify remaining events
        $remainingEvents = WebhookEvent::all();
        $this->assertCount(2, $remainingEvents);

        $remainingEventIds = $remainingEvents->pluck('square_event_id')->toArray();
        $this->assertContains('old_pending_event', $remainingEventIds);
        $this->assertContains('recent_event', $remainingEventIds);
    }

    /**
     * Test that the status column accepts the resolution statuses added for
     * events a processor run superseded, and for events too old to be in flight.
     */
    public function test_webhook_event_persists_superseded_and_stale_statuses(): void
    {
        $subscription = $this->createTestWebhookSubscription();

        foreach ([WebhookEvent::STATUS_SUPERSEDED, WebhookEvent::STATUS_STALE] as $status) {
            $event = WebhookEvent::create([
                'square_event_id'         => "event_{$status}",
                'event_type'              => 'order.updated',
                'event_time'              => now(),
                'event_data'              => ['test' => 'data'],
                'status'                  => $status,
                'webhook_subscription_id' => $subscription->id,
            ]);

            $this->assertEquals($status, $event->fresh()->status);
        }
    }

    /**
     * Test that the superseded and stale scopes each return only their own status.
     */
    public function test_superseded_and_stale_scopes_return_only_their_own_status(): void
    {
        $subscription = $this->createTestWebhookSubscription();

        $statuses = [
            WebhookEvent::STATUS_PENDING,
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::STATUS_FAILED,
            WebhookEvent::STATUS_SUPERSEDED,
            WebhookEvent::STATUS_STALE,
        ];

        foreach ($statuses as $status) {
            WebhookEvent::create([
                'square_event_id'         => "scope_event_{$status}",
                'event_type'              => 'order.updated',
                'event_time'              => now(),
                'event_data'              => ['test' => 'data'],
                'status'                  => $status,
                'webhook_subscription_id' => $subscription->id,
            ]);
        }

        $superseded = WebhookEvent::superseded()->get();
        $this->assertCount(1, $superseded);
        $this->assertEquals('scope_event_superseded', $superseded->first()->square_event_id);

        $stale = WebhookEvent::stale()->get();
        $this->assertCount(1, $stale);
        $this->assertEquals('scope_event_stale', $stale->first()->square_event_id);
    }

    /**
     * Test that the order id object keys stay in step with the event type map.
     *
     * A consumer querying `order_id` in SQL cannot call `getOrderId()` per row, so it
     * needs the key set as data. This asserts the two never drift apart.
     */
    public function test_order_id_object_keys_match_the_event_type_map(): void
    {
        $eventTypes = [
            'order.created',
            'order.fulfillment.updated',
            'order.updated',
            'payment.created',
            'payment.updated',
            'refund.created',
            'refund.updated',
        ];

        $expected = [];

        foreach ($eventTypes as $eventType) {
            $expected[] = WebhookEvent::getObjectTypeKey($eventType);
        }

        $expected = array_values(array_unique($expected));

        $this->assertEqualsCanonicalizing($expected, WebhookEvent::orderIdObjectKeys());
    }
}
