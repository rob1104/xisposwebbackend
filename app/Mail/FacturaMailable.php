<?php

namespace App\Mail;

use App\Models\Cfdi;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class FacturaMailable extends Mailable
{
    use Queueable, SerializesModels;

    public Cfdi $cfdi;
    public $subject;

    /**
     * Create a new message instance.
     */
    public function __construct(Cfdi $cfdi)
    {
        $this->cfdi = $cfdi;
        $this->subject = "HA RECIBIDO UN CFDI | Factura: {$cfdi->serie}-{$cfdi->folio}";
    }

    public function build()
    {
        $diskPath = storage_path('app/private/');

        $email = $this->view('emails.factura')
            ->subject($this->subject);

        // 1. Adjuntar XML
        if ($this->cfdi->xml_path && Storage::disk('private')->exists($this->cfdi->xml_path)) {
            $email->attach($diskPath . $this->cfdi->xml_path, [
                'as' => "Factura_{$this->cfdi->serie}{$this->cfdi->folio}.xml",
                'mime' => 'application/xml',
            ]);
        }

        // 2. Adjuntar PDF
        if ($this->cfdi->pdf_path && Storage::disk('private')->exists($this->cfdi->pdf_path)) {
            $email->attach($diskPath . $this->cfdi->pdf_path, [
                'as' => "Factura_{$this->cfdi->serie}{$this->cfdi->folio}.pdf",
                'mime' => 'application/pdf',
            ]);
        }

        return $email;
    }
}
