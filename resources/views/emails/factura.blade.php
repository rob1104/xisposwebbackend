<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 0; }
        .container { width: 100%; max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden; }
        .header { background-color: #1e3a8a; /* indigo-10 */ padding: 30px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 1px; }
        .content { padding: 40px 30px; color: #374151; }
        .greeting { font-size: 18px; font-weight: bold; margin-bottom: 20px; }
        .details-box { background-color: #f8fafc; border-left: 4px solid #1e3a8a; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .detail-label { font-weight: bold; color: #64748b; }
        .detail-value { font-weight: bold; color: #1e293b; }
        .footer { padding: 20px; text-align: center; color: #9ca3af; font-size: 12px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; }
        .btn { display: inline-block; background-color: #1e3a8a; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <h1>NUEVA FACTURA EMITIDA</h1>
        </div>

        <div class="content">
            <div class="greeting">Estimado(a) {{ $cfdi->cliente->razon_social }},</div>

            <p>Le informamos que se ha generado exitosamente su Comprobante Fiscal Digital por Internet (CFDI).</p>
            <p>Adjunto a este correo encontrará los archivos <strong>XML</strong> y <strong>PDF</strong> correspondientes a su transacción.</p>

            <div class="details-box">
                <div class="detail-row">
                    <span class="detail-label">Folio Fiscal:</span>
                    <span class="detail-value">{{ $cfdi->serie }}-{{ $cfdi->folio }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Fecha de Emisión:</span>
                    <span class="detail-value">{{ $cfdi->created_at->format('d/m/Y h:i A') }}</span>
                </div>
                <div class="detail-row" style="margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px;">
                    <span class="detail-label" style="font-size: 16px; color: #1e3a8a;">Total:</span>
                    <span class="detail-value" style="font-size: 18px; color: #1e3a8a;">${{ number_format($cfdi->total, 2) }} MXN</span>
                </div>
            </div>

            <p>Este correo ha sido generado automáticamente. Si tiene alguna duda sobre su facturación, no dude en contactarnos.</p>

            <div style="text-align: center;">
                <span class="btn">Documentos Adjuntos</span>
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $cfdi->sucursal->nombre ?? 'Nuestra Empresa' }}. Todos los derechos reservados.</p>
            <p>Este correo contiene información fiscal sensible.</p>
        </div>
    </div>
</div>
</body>
</html>
