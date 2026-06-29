<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Mail\CustomerOfferMail;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferEmailSender;
use App\Modules\Customers\Models\Customer;
use App\Modules\Emails\Models\Email;
use App\Modules\TenantSettings\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class CustomerOfferEmailSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_marks_offer_and_email_as_sent(): void
    {
        Mail::fake();

        [$offer, $user] = $this->scaffold();
        $this->actingAs($user);

        $email = app(CustomerOfferEmailSender::class)->send($offer, [
            'to' => 'buyer@example.com, manager@example.com',
            'cc' => 'cc@example.com',
            'subject' => 'Your offer CO-001',
            'body' => '<p>Hi, here is your offer.</p>',
        ]);

        $this->assertSame('sent', $email->status);
        $this->assertNotNull($email->sent_at);
        $this->assertSame('buyer@example.com, manager@example.com', $email->to);
        $this->assertSame('cc@example.com', $email->cc);
        $this->assertSame($user->id, $email->sent_by);
        $this->assertSame(CustomerOffer::class, $email->related_type);
        $this->assertSame($offer->id, $email->related_id);

        $offer->refresh();
        $this->assertSame('sent', $offer->status);
        $this->assertNotNull($offer->sent_at);
        $this->assertSame('Your offer CO-001', $offer->email_subject);
        $this->assertSame('<p>Hi, here is your offer.</p>', $offer->email_body);

        Mail::assertSent(CustomerOfferMail::class, function (CustomerOfferMail $mail): bool {
            return $mail->hasTo('buyer@example.com')
                && $mail->hasTo('manager@example.com')
                && $mail->hasCc('cc@example.com')
                && $mail->subjectLine === 'Your offer CO-001';
        });
    }

    public function test_sending_failure_records_error_without_changing_offer(): void
    {
        [$offer, $user] = $this->scaffold();
        $this->actingAs($user);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('cc')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));

        $email = app(CustomerOfferEmailSender::class)->send($offer, [
            'to' => 'buyer@example.com',
            'cc' => null,
            'subject' => 'Subject',
            'body' => '<p>Body</p>',
        ]);

        $this->assertSame('failed', $email->status);
        $this->assertSame('SMTP unavailable', $email->error_message);
        $this->assertNull($email->sent_at);

        $offer->refresh();
        $this->assertSame('draft', $offer->status);
        $this->assertNull($offer->sent_at);
    }

    public function test_defaults_prefer_offer_values_over_tenant_settings(): void
    {
        [$offer] = $this->scaffold();

        $offer->update([
            'email_subject' => 'Stored subject',
            'email_body' => 'Stored body',
        ]);

        $defaults = app(CustomerOfferEmailSender::class)->defaultsFor($offer);

        $this->assertSame('Stored subject', $defaults['subject']);
        $this->assertSame('Stored body', $defaults['body']);
    }

    public function test_defaults_fall_back_to_tenant_settings_then_to_built_in(): void
    {
        [$offer] = $this->scaffold(offerNumber: 'CO-99');

        TenantSetting::create([
            'tenant_id' => $offer->tenant_id,
            'key' => CustomerOfferEmailSender::SUBJECT_TEMPLATE_KEY,
            'value' => 'Tenant template subject',
        ]);

        $defaults = app(CustomerOfferEmailSender::class)->defaultsFor($offer);

        $this->assertSame('Tenant template subject', $defaults['subject']);
        $this->assertStringContainsString('CO-99', $defaults['body']);
    }

    /**
     * @return array{0: CustomerOffer, 1: User}
     */
    private function scaffold(string $offerNumber = 'CO-001'): array
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
            'email' => 'customer@example.com',
        ]);
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => $offerNumber,
            'currency' => 'EUR',
            'status' => 'draft',
        ]);
        $offer->refresh();

        $user = User::factory()->create();

        return [$offer, $user];
    }
}
