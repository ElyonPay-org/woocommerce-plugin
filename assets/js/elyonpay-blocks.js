/**
 * ElyonPay WooCommerce Blocks integration.
 *
 * File: assets/js/elyonpay-blocks.js
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting }            = window.wc.wcSettings;
const { createElement }         = window.wp.element;
const { __ }                    = window.wp.i18n;
const { decodeEntities }        = window.wp.htmlEntities;

const settings    = getSetting( "elyonpay_data", {} );
const title       = decodeEntities( settings.title || "" ) || __( "ElyonPay", "elyonpay-woocommerce" );
const description = decodeEntities( settings.description || "" );

/**
 * Checkout content (redirect gateway: no input fields).
 */
const Content = () => {
    const children = [];

    if ( description ) {
        children.push(
            createElement( "p", { key: "description", style: { margin: "0 0 1em 0" } }, description )
        );
    }

    if ( settings.testmode ) {
        children.push(
            createElement(
                "div",
                {
                    key: "testmode",
                    style: {
                        background: "#fff3cd",
                        border: "1px solid #ffeaa7",
                        borderRadius: "4px",
                        padding: "8px 12px",
                        marginBottom: "1em",
                        fontSize: "0.875em",
                    },
                },
                [
                    createElement( "strong", { key: "label" }, __( "Mode Test : ", "elyonpay-woocommerce" ) ),
                    __( "Les paiements seront traités en mode test.", "elyonpay-woocommerce" ),
                ]
            )
        );
    }

    return createElement( "div", { className: "wc-block-components-payment-method-content" }, children );
};

/**
 * Payment method label.
 */
const Label = ( { components } ) => {
    const { PaymentMethodLabel } = components;
    return createElement( PaymentMethodLabel, { text: title, icon: settings.icon } );
};

registerPaymentMethod( {
    name: "elyonpay",
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: title,
    supports: {
        features: settings.supports || [ "products" ],
    },
} );
