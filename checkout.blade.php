{{-- ═══════════════════════════════════════════════════════════════════════════
     Square Web Payments SDK Checkout Partial
     Location: payment-processors/square/checkout.blade.php
     ═══════════════════════════════════════════════════════════════════════════ --}}
<div class="space-y-4">
    <div>
        <label class="text-xs font-bold text-slate-400 block mb-2 uppercase tracking-wider font-sans">
            @label('review.card_details', 'Card Details')
        </label>
        <div id="square-card-container" wire:ignore
             class="px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl focus-within:border-indigo-500 transition-colors min-h-[90px]"></div>
        <p class="mt-1.5 text-xs text-slate-400">
            @label('review.square_message', 'Secured by Square. Your card details are never stored on our servers.')
        </p>
    </div>
</div>

@if(!empty($squareSdkUrl))
    <script src="{{ $squareSdkUrl }}" defer></script>
@endif

<script>
window.PaymentProcessorPlugins = window.PaymentProcessorPlugins || {};

window.PaymentProcessorPlugins['square'] = {
    payments: null,
    card: null,
    appId: @js($squareAppId ?? ''),
    locationId: @js($squareLocationId ?? ''),

    async init(ctx) {
        let elapsed = 0;
        while (typeof window.Square === 'undefined' && elapsed < 8000) {
            await new Promise(r => setTimeout(r, 100));
            elapsed += 100;
        }

        if (typeof window.Square === 'undefined') {
            ctx.errorMessage = 'Failed to load Square Web Payments SDK. Please refresh the page.';
            return;
        }

        try {
            // If appId or locationId wasn't preloaded, fetch from preparePayment()
            if (!this.appId || !this.locationId) {
                const prep = await ctx.$wire.preparePayment();
                if (prep && !prep.error) {
                    this.appId = prep.appId || this.appId;
                    this.locationId = prep.locationId || this.locationId;
                }
            }

            if (!this.appId || !this.locationId) {
                ctx.errorMessage = 'Square Application ID or Location ID is not configured.';
                return;
            }

            this.payments = window.Square.payments(this.appId, this.locationId);

            if (this.card) {
                try { await this.card.destroy(); } catch (e) {}
            }

            this.card = await this.payments.card();
            await this.card.attach('#square-card-container');
        } catch (err) {
            console.error('Square Web Payments SDK init failed:', err);
            ctx.errorMessage = err.message || 'Failed to initialize Square card form.';
        }
    },

    async reboot(ctx) {
        const container = document.getElementById('square-card-container');
        if (container) {
            container.innerHTML = '';
        }
        await this.init(ctx);
    },

    async handlePlaceOrder(ctx) {
        ctx.errorMessage = '';
        ctx.processing = true;

        try {
            // Step 1: Validate cart & get current order amount/currency from server
            const prep = await ctx.$wire.preparePayment();
            if (prep.error) {
                ctx.errorMessage = prep.error;
                ctx.processing = false;
                if (prep.redirect) {
                    window.location.href = prep.redirect;
                }
                return;
            }

            if (prep.processor === 'free') {
                await ctx.$wire.placeOrder('');
                return;
            }

            if (!this.card) {
                await this.init(ctx);
            }

            if (!this.card) {
                ctx.errorMessage = 'Square payment form is not ready. Please try again.';
                ctx.processing = false;
                return;
            }

            // Step 2: Tokenize card + perform 3DS/SCA buyer verification
            const fullName = @js($user->name ?? '');
            const nameParts = fullName.trim().split(/\s+/);
            const givenName = nameParts[0] || 'Customer';
            const familyName = nameParts.length > 1 ? nameParts.slice(1).join(' ') : '';

            const verificationDetails = {
                amount: String(prep.amount || '{{ number_format((float) $total, 2, ".", "") }}'),
                currencyCode: String(prep.currency || '{{ strtoupper($currencyCode ?? "USD") }}'),
                intent: 'CHARGE',
                customerInitiated: true,
                sellerKeyedIn: false,
                billingContact: {
                    givenName: givenName,
                    familyName: familyName,
                    email: @js($user->email ?? ''),
                    addressLines: [@js($user->shipping_address1 ?? '')].filter(Boolean),
                    city: @js($user->shipping_city ?? ''),
                    state: @js($user->shipping_state ?? ''),
                    postalCode: @js($user->shopping_postalcode ?? ''),
                    countryCode: @js(strtoupper($user->shipping_countrycode ?? 'US')),
                }
            };

            const tokenResult = await this.card.tokenize(verificationDetails);

            if (tokenResult.status !== 'OK') {
                let errMsg = 'Card verification failed. Please check your card details.';
                if (tokenResult.errors && tokenResult.errors.length > 0) {
                    errMsg = tokenResult.errors.map(e => e.message).join(' ');
                }
                ctx.errorMessage = errMsg;
                ctx.processing = false;
                return;
            }

            // Step 3: Send single-use sourceId token to OrderReview::placeOrder()
            await ctx.$wire.placeOrder(tokenResult.token);
        } catch (err) {
            console.error('Square handlePlaceOrder error:', err);
            ctx.errorMessage = err.message || 'An unexpected error occurred while processing your Square payment.';
            ctx.processing = false;
        }
    }
};
</script>
