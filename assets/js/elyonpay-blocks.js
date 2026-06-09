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

const settings      = getSetting( "elyonpay_data", {} );
const title         = decodeEntities( settings.title || "" ) || __( "ElyonPay", "elyonpay-for-woocommerce" );
const description   = decodeEntities( settings.description || "" );
const paymentIcons  = settings.payment_icons || [];

/**
 * Payment method label: title text + row of small operator logos.
 */
const Label = () => {
    var children = [
        createElement( "span", { key: "title" }, title ),
    ];

    if ( paymentIcons.length ) {
        var icons = paymentIcons.map( function( icon, i ) {
            return createElement( "img", {
                key:   "icon-" + i,
                src:   icon.src,
                alt:   icon.alt,
                style: {
                    height:       "24px",
                    width:        "auto",
                    borderRadius: "3px",
                    objectFit:    "contain",
                },
            } );
        } );

        children.push(
            createElement( "span", {
                key:   "icons",
                style: {
                    display:    "inline-flex",
                    alignItems: "center",
                    gap:        "6px",
                    marginLeft: "8px",
                },
            }, icons )
        );
    }

    return createElement( "span", {
        style: {
            display:    "inline-flex",
            alignItems: "center",
            flexWrap:   "wrap",
            gap:        "4px",
        },
    }, children );
};

/**
 * Checkout content (redirect gateway: no input fields).
 */
const Content = () => {
    var children = [];

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
                        background:   "#fff3cd",
                        border:       "1px solid #ffeaa7",
                        borderRadius: "4px",
                        padding:      "8px 12px",
                        marginBottom: "1em",
                        fontSize:     "0.875em",
                    },
                },
                [
                    createElement( "strong", { key: "label" }, __( "Mode Test : ", "elyonpay-for-woocommerce" ) ),
                    __( "Les paiements seront traités en mode test.", "elyonpay-for-woocommerce" ),
                ]
            )
        );
    }

    return createElement( "div", { className: "wc-block-components-payment-method-content" }, children );
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
