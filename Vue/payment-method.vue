<template>
    <div class="row mt-2">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="card payment-method-wrap">
                <div class="card-header">
                    <h3 class="card-title float-left" style="">
                        {{ getLocale == 'ar' ? "اختر وسيلة الدفع" : "Choose payment method"}}
                    </h3>
                    <template v-if="!!paymentData && paymentData.credit_card_enabled === 'true'">
                        <ul class="cstm-paymant-wdgt widget-list">
                            <li class="widget-list-item mr-3" v-show="paymentData.currency==='SAR'">
                                <img width="100px" src="/web-assets/img/mada-card.png" class="img-thumbnail" alt="Mada card">
                            </li>
                            <li class="widget-list-item mr-3">
                                <img width="70px" src="/web-assets/img/master-card.png" class="img-thumbnail"
                                    alt="Master card">
                            </li>
                            <li class="widget-list-item mr-3">
                                <img width="100px" src="/web-assets/img/visa-card.png" class="img-thumbnail" alt="Visa card">
                            </li>
                        </ul>

                    </template>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 col-12">
                            <template v-if="!!paymentData && paymentData.cod === 'enable'">
                                <div class="custom-control custom-radio my-3">
                                    <input class="custom-control-input payment-type" type="radio" id="payment-type-cod"
                                        value="cod"
                                        v-model="paymentType"
                                        @change="paymentTypeChanged"
                                        name="payment-type">
                                    <label class="custom-control-label font-size-md" for="payment-type-cod">
                                        {{ getLocale == 'ar' ? "COD (رسوم إضافية": "COD (Extra charges"}} {{paymentData.cod_charges}} {{paymentData.currency}} )
                                    </label>
                                </div>
                            </template>
                            <template v-if="!!paymentData && paymentData.credit_card_enabled === 'true' && paymentData.checkout_gateway_enabled === false">
                                <div class="custom-control custom-radio my-2">
                                    <input class="custom-control-input payment-type" type="radio" value="cc"
                                        v-model="paymentType"
                                        @change="paymentTypeChanged"
                                        id="payment-type-cc"
                                        name="payment-type">
                                    <label class="custom-control-label font-size-md" for="payment-type-cc">
                                        {{ getLocale == 'ar' ? "بطاقة الائتمان / بطاقة مدى البنكية" : "Credit Card / mada bank Card"}}
                                    </label>
                                </div>
                            </template>
                            <template v-if="!!paymentData && paymentData.checkout_gateway_enabled === true">
                                <div class="custom-control custom-radio my-2">
                                    <input class="custom-control-input payment-type" type="radio" value="cc-checkout"
                                        v-model="paymentType"
                                        @change="paymentTypeChanged"
                                        id="payment-type-cc"
                                        name="payment-type">
                                    <label class="custom-control-label font-size-md" for="payment-type-cc">
                                        {{ getLocale == 'ar' ? "بطاقة الائتمان" : "Credit Card"}}
                                    </label>
                                </div>
                            </template>

                            <template v-if="!!paymentData && paymentData.installments_enabled">
                                <div class="custom-control custom-radio my-3">
                                    <input class="custom-control-input payment-type" type="radio" value="cci"
                                        v-model="paymentType"
                                        @change="paymentTypeChanged"
                                        id="payment-type-cci"
                                        name="payment-type">
                                    <label class="custom-control-label font-size-md" for="payment-type-cci">
                                        {{ getLocale == 'ar' ? "بطاقة ائتمان بالتقسيط" : "Credit Card with Installments"}}
                                    </label>
                                </div>
                            </template>
                        </div>
                        <div class="col-md-6 col-12">
                            <template  v-if="!!paymentData && paymentType === 'cod'" >
                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary btn-sm btn-custom-pos" @click="submitOrder">
                                        {{ getLocale == 'ar' ? "أكد الطلب" : "Submit Order"}}
                                    </button>
                                </div>
                            </template>
                            <template v-if="iFrame!==null">
                                    <div class="w-100" v-html="iFrame"></div>
                            </template>
                            <template v-if="iFramei!==null">
                                    <div class="w-100" v-html="iFramei"></div>
                            </template>
                        </div>
                    </div>



                </div>
            </div>
        </div>
    </div>
</template>
<script type="text/babel">
    export default {
        props: {
            paymentData: Object,
            form: null,
            iFrame: String,
            iFramei: String,
            creditCards: Array,
            getLocale:String
        },
        data() {
            return {
                paymentType: null,
                selectedCreditCard: null,
            }
        },
        methods: {
            paymentTypeChanged() {
                const {paymentType} = this;

                this.$emit('payment-type-changed', {paymentType});
            },
            submitOrder() {
                const {paymentType, selectedCreditCard} = this;

                if (null == paymentType) {
                    this.$notify({
                        group: 'app',
                        title: 'Cartlow',
                        text: 'Please select payment type',
                        type: 'warn',
                    });
                    return;
                }

                if(paymentType === 'cc' && selectedCreditCard === null){
                    this.$notify({
                        group: 'app',
                        title: 'Cartlow',
                        text: 'Please select credit card',
                        type: 'warn',
                    });
                    return;
                }

                this.$emit('submit-order',{paymentType, selectedCreditCard});
            },

        },
        created() {
            var that = this;
            window.addEventListener("message", function (event) {

                if (event.data.action === 'transactionSucceeded') {
                    that.$emit('transactionSucceeded', event);
                } else if (event.data.action === 'transactionFailed') {
                    that.$emit('transactionFailed', event);
                }

            }, false);
        }
    }
</script>

