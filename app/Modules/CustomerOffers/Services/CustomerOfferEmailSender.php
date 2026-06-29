<?php

namespace App\Modules\CustomerOffers\Services;

use App\Modules\CustomerOffers\Mail\CustomerOfferMail;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Emails\Models\Email;
use App\Modules\TenantSettings\Models\TenantSetting;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CustomerOfferEmailSender
{
    public const SUBJECT_TEMPLATE_KEY = 'customer_offer.email_subject_template';

    public const BODY_TEMPLATE_KEY = 'customer_offer.email_body_template';

    /**
     * @param  array{to: string, cc?: ?string, subject: string, body: string}  $data
     */
    public function send(CustomerOffer $offer, array $data): Email
    {
        $to = $this->splitAddresses($data['to']);
        $cc = $this->splitAddresses($data['cc'] ?? null);
        $subject = $data['subject'];
        $body = $data['body'];

        $email = Email::create([
            'tenant_id' => $offer->tenant_id,
            'related_type' => CustomerOffer::class,
            'related_id' => $offer->getKey(),
            'to' => implode(', ', $to),
            'cc' => $cc !== [] ? implode(', ', $cc) : null,
            'subject' => $subject,
            'body' => $body,
            'status' => 'pending',
            'sent_by' => auth()->id(),
        ]);

        try {
            Mail::to($to)
                ->cc($cc)
                ->send(new CustomerOfferMail($subject, $body));

            $now = now();

            $email->update([
                'status' => 'sent',
                'sent_at' => $now,
            ]);

            $offer->update([
                'status' => 'sent',
                'sent_at' => $now,
                'email_subject' => $subject,
                'email_body' => $body,
            ]);
        } catch (Throwable $exception) {
            $email->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $email->refresh();
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function defaultsFor(CustomerOffer $offer): array
    {
        return [
            'subject' => $offer->email_subject
                ?: $this->settingValue($offer->tenant_id, self::SUBJECT_TEMPLATE_KEY)
                ?? $this->fallbackSubject($offer),
            'body' => $offer->email_body
                ?: $this->settingValue($offer->tenant_id, self::BODY_TEMPLATE_KEY)
                ?? $this->fallbackBody($offer),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitAddresses(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $piece): string => trim($piece),
            preg_split('/[,;]/', $value) ?: [],
        )));
    }

    private function settingValue(int $tenantId, string $key): ?string
    {
        return TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->value('value');
    }

    private function fallbackSubject(CustomerOffer $offer): string
    {
        return $offer->offer_number !== null
            ? "Customer offer {$offer->offer_number}"
            : 'Customer offer';
    }

    private function fallbackBody(CustomerOffer $offer): string
    {
        $reference = $offer->offer_number ?? '#'.$offer->getKey();

        return '<p>Hello,</p><p>Please find our offer '.e($reference).' below.</p>';
    }
}
