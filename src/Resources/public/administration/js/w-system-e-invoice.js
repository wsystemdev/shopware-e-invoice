/* W System E-Invoice: order detail card (plain admin plugin file, no build step needed). */
(function () {
    const { Component, Locale } = Shopware;

    Locale.extend('en-GB', {
        'wsystem-einvoice': {
            title: 'E-invoice (W System)',
            none: 'No e-invoice has been created for this order yet.',
            create: 'Create e-invoice',
            recreate: 'Create again',
            download: 'Download XML',
            valid: 'Valid',
            invalid: 'Failed validation',
            format: 'Format',
            number: 'Invoice number',
            created: 'Created',
            errors: 'Errors to fix',
            lastError: 'Last error',
            done: 'E-invoice created.',
            failed: 'E-invoice could not be created.',
            testKey: 'Test saved API key',
            testHint: 'Save the settings first. The test checks the saved key against api.wsystem.dev.',
            hint: 'The order data is sent to api.wsystem.dev to build and check the e-invoice. Buyer reference (Leitweg-ID): see the "E-invoice (W System)" custom field set on this order or its customer.',
        },
    });
    Locale.extend('de-DE', {
        'wsystem-einvoice': {
            title: 'E-Rechnung (W System)',
            none: 'Für diese Bestellung wurde noch keine E-Rechnung erstellt.',
            create: 'E-Rechnung erstellen',
            recreate: 'Neu erstellen',
            download: 'XML herunterladen',
            valid: 'Gültig',
            invalid: 'Prüfung fehlgeschlagen',
            format: 'Format',
            number: 'Rechnungsnummer',
            created: 'Erstellt',
            errors: 'Zu behebende Fehler',
            lastError: 'Letzter Fehler',
            done: 'E-Rechnung erstellt.',
            failed: 'E-Rechnung konnte nicht erstellt werden.',
            testKey: 'Gespeicherten API-Schlüssel testen',
            testHint: 'Speichern Sie die Einstellungen zuerst. Der Test prüft den gespeicherten Schlüssel gegen api.wsystem.dev.',
            hint: 'Die Bestelldaten werden zur Erstellung und Prüfung der E-Rechnung an api.wsystem.dev gesendet. Käuferreferenz (Leitweg-ID): siehe Zusatzfeld-Set „E-Rechnung (W System)“ an dieser Bestellung oder am Kunden.',
        },
    });

    Component.register('wsystem-einvoice-api-test', {
        template: `
<div class="wsystem-einvoice-api-test" style="margin-bottom:24px">
    <sw-button size="small" :isLoading="busy" @click="run">{{ $tc('wsystem-einvoice.testKey') }}</sw-button>
    <p v-if="message" :style="{ marginTop: '8px', color: ok ? '#1a7f37' : '#b42318' }">{{ message }}</p>
    <p v-else style="margin-top:8px;font-size:12px;color:#6b7280">{{ $tc('wsystem-einvoice.testHint') }}</p>
</div>`,
        inject: ['loginService'],
        data() { return { busy: false, ok: true, message: '' }; },
        methods: {
            run() {
                this.busy = true;
                Shopware.Application.getContainer('init').httpClient
                    .post('/_action/wsystem-einvoice/test-api', {}, { headers: { Authorization: `Bearer ${this.loginService.getToken()}` } })
                    .then((r) => { this.ok = !!r.data.ok; this.message = r.data.message; })
                    .catch(() => { this.ok = false; this.message = 'Request failed.'; })
                    .finally(() => { this.busy = false; });
            },
        },
    });

    Component.override('sw-order-detail-general', {
        template: `
{% block sw_order_detail_general_line_items_card %}
{% parent %}
<sw-card class="wsystem-einvoice-card" :title="$tc('wsystem-einvoice.title')" position-identifier="wsystem-einvoice">
    <div v-if="wsEi.loaded">
        <p v-if="!wsEi.exists && !wsEi.lastError">{{ $tc('wsystem-einvoice.none') }}</p>
        <div v-if="wsEi.exists">
            <p>
                <strong v-if="wsEi.valid" style="color:#1a7f37">&#10003; {{ $tc('wsystem-einvoice.valid') }}</strong>
                <strong v-else style="color:#b42318">&#10007; {{ $tc('wsystem-einvoice.invalid') }}</strong>
                &middot; {{ $tc('wsystem-einvoice.format') }}: {{ wsEi.format }}
                &middot; {{ $tc('wsystem-einvoice.number') }}: {{ wsEi.number }}
                &middot; {{ $tc('wsystem-einvoice.created') }}: {{ wsEi.created }}
            </p>
            <div v-if="wsEi.errors.length">
                <p><strong>{{ $tc('wsystem-einvoice.errors') }}</strong></p>
                <ul><li v-for="e in wsEi.errors"><code>{{ e.rule_id }}</code> {{ e.message }} <em v-if="e.fix">{{ e.fix }}</em></li></ul>
            </div>
            <p v-if="wsEi.mismatch" style="color:#b54708">{{ wsEi.mismatch }}</p>
        </div>
        <p v-if="wsEi.lastError" style="color:#b42318">{{ $tc('wsystem-einvoice.lastError') }}: {{ wsEi.lastError }}</p>
        <p style="margin-top:12px">
            <sw-button variant="primary" size="small" :isLoading="wsEi.busy" @click="wsEiGenerate">
                {{ wsEi.exists ? $tc('wsystem-einvoice.recreate') : $tc('wsystem-einvoice.create') }}
            </sw-button>
            <sw-button v-if="wsEi.exists" size="small" @click="wsEiDownload">{{ $tc('wsystem-einvoice.download') }}</sw-button>
        </p>
        <p style="margin-top:8px;font-size:12px;color:#6b7280">{{ $tc('wsystem-einvoice.hint') }}</p>
    </div>
</sw-card>
{% endblock %}`,

        data() {
            return { wsEi: { loaded: false, busy: false, exists: false, valid: false, format: '', number: '', created: '', errors: [], mismatch: '', lastError: '' } };
        },

        computed: {
            wsEiOrderId() { return this.orderId || (this.order && this.order.id) || null; },
        },

        watch: {
            wsEiOrderId: { immediate: true, handler() { this.wsEiLoad(); } },
        },

        methods: {
            wsEiClient() {
                return Shopware.Application.getContainer('init').httpClient;
            },
            wsEiHeaders() {
                return { Authorization: `Bearer ${Shopware.Service('loginService').getToken()}` };
            },
            wsEiApply(d) {
                this.wsEi = Object.assign({}, this.wsEi, d, { loaded: true, busy: false });
            },
            wsEiLoad() {
                if (!this.wsEiOrderId) { return; }
                this.wsEiClient().get(`/_action/wsystem-einvoice/order/${this.wsEiOrderId}/status`, { headers: this.wsEiHeaders() })
                    .then((r) => this.wsEiApply(r.data))
                    .catch(() => { this.wsEi.loaded = false; });
            },
            wsEiGenerate() {
                this.wsEi.busy = true;
                this.wsEiClient().post(`/_action/wsystem-einvoice/order/${this.wsEiOrderId}/generate`, {}, { headers: this.wsEiHeaders() })
                    .then((r) => {
                        this.wsEiApply(r.data);
                        const res = r.data.result || {};
                        if (res.error) {
                            this.createNotificationError({ message: res.error });
                        } else {
                            this.createNotificationSuccess({ message: this.$tc('wsystem-einvoice.done') });
                        }
                    })
                    .catch(() => {
                        this.wsEi.busy = false;
                        this.createNotificationError({ message: this.$tc('wsystem-einvoice.failed') });
                    });
            },
            wsEiDownload() {
                this.wsEiClient().get(`/_action/wsystem-einvoice/order/${this.wsEiOrderId}/download`, { headers: this.wsEiHeaders(), responseType: 'blob' })
                    .then((r) => {
                        const url = window.URL.createObjectURL(new Blob([r.data], { type: 'application/xml' }));
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `e-invoice-${this.wsEi.number || this.wsEiOrderId}.xml`;
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(url);
                    });
            },
        },
    });
})();
