( function() {
    if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
        return;
    }

    var paymentMethodData = window.wc.wcSettings.getSetting( 'paymentMethodData', {} );
    var settings = ( paymentMethodData && paymentMethodData.dkpay ) ? paymentMethodData.dkpay : window.wc.wcSettings.getSetting( 'dkpay_data', {} );
    var el = window.wp.element.createElement;
    var useEffect = window.wp.element.useEffect;
    var useState = window.wp.element.useState;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;

    function Label() {
        return el( 'span', null, settings.title || 'DKPay' );
    }

    function renderIcon( method ) {
        if ( method && method.icon_html ) {
            return el( 'span', { 'aria-hidden': true, dangerouslySetInnerHTML: { __html: String( method.icon_html ) } } );
        }
        return null;
    }

    function Content( props ) {
        var methods = settings.methods || [];
        var initialKey = methods.length ? methods[ 0 ].key : '';
        var state = useState( initialKey );
        var selected = state[ 0 ];
        var setSelected = state[ 1 ];

        useEffect(
            function() {
                function onImgError( e ) {
                    var t = e && e.target ? e.target : null;
                    if ( ! t || ! t.tagName || String( t.tagName ).toUpperCase() !== 'IMG' ) return;
                    if ( ! t.classList || ! t.classList.contains( 'dkpay-method-icon-img' ) ) return;
                    t.style.display = 'none';
                    if ( t.parentElement && String( t.parentElement.tagName ).toUpperCase() === 'SPAN' ) {
                        t.parentElement.style.display = 'none';
                    }
                }
                document.addEventListener( 'error', onImgError, true );
                return function() {
                    document.removeEventListener( 'error', onImgError, true );
                };
            },
            []
        );

        useEffect(
            function() {
                if ( ! props || ! props.eventRegistration || ! props.eventRegistration.onPaymentProcessing ) {
                    return;
                }

                return props.eventRegistration.onPaymentProcessing( function() {
                    var visitorId = '';
                    try {
                        visitorId = window.localStorage.getItem( 'dkpay_visitor_id' ) || '';
                        if ( ! visitorId ) {
                            visitorId = ( window.crypto && window.crypto.randomUUID ) ? window.crypto.randomUUID() : String( Date.now() ) + '-' + String( Math.random() ).slice( 2 );
                            window.localStorage.setItem( 'dkpay_visitor_id', visitorId );
                        }
                    } catch ( e ) {}

                    var utmSource = 'wp';
                    var utmMedium = 'checkout';
                    try {
                        var search = new window.URLSearchParams( window.location.search || '' );
                        utmSource = search.get( 'utm_source' ) || utmSource;
                        utmMedium = search.get( 'utm_medium' ) || utmMedium;
                    } catch ( e2 ) {}

                    var sourceDevice = 'desktop';
                    try {
                        sourceDevice = window.matchMedia && window.matchMedia( '(pointer:coarse)' ).matches ? 'phone' : 'desktop';
                    } catch ( e3 ) {}

                    var sysinfo = {};
                    try {
                        sysinfo = {
                            os: '',
                            timezone: ( window.Intl && Intl.DateTimeFormat ) ? ( Intl.DateTimeFormat().resolvedOptions().timeZone || '' ) : '',
                            browser: '',
                            language: ( navigator && navigator.language ) ? navigator.language : '',
                            screen_size: ( window.screen && window.screen.width ) ? ( String( window.screen.width ) + 'X' + String( window.screen.height ) ) : '',
                            viewport_size: ( window.innerWidth ? ( String( window.innerWidth ) + 'X' + String( window.innerHeight ) ) : '' ),
                            ip: '',
                            user_agent: ( navigator && navigator.userAgent ) ? navigator.userAgent : '',
                            cookies: ''
                        };
                    } catch ( e4 ) {}

                    return {
                        type: 'success',
                        meta: {
                            paymentMethodData: {
                                _dkpay_selected_method: selected || '',
                                _dkpay_visitor_id: visitorId || '',
                                _dkpay_utm_source: utmSource || 'wp',
                                _dkpay_utm_medium: utmMedium || 'checkout',
                                _dkpay_source_device: sourceDevice || '',
                                _dkpay_customer_sysinfo: JSON.stringify( sysinfo || {} )
                            }
                        }
                    };
                } );
            },
            [ selected ]
        );

        return el(
            'div',
            { className: 'dkpay-blocks-content' },
            settings.description ? el( 'p', null, settings.description ) : null,
            methods.map( function( method ) {
                return el(
                    'label',
                    {
                        key: method.key,
                        className: 'dkpay-block-method',
                        style: { display: 'flex', gap: '10px', alignItems: 'center', padding: '8px 0', cursor: 'pointer' }
                    },
                    el( 'input', {
                        type: 'radio',
                        name: 'dkpay_method_choice',
                        value: method.key,
                        checked: selected === method.key,
                        onChange: function() {
                            setSelected( method.key );
                        }
                    } ),
                    renderIcon( method ),
                    el( 'span', null, method.label )
                );
            } )
        );
    }

    registerPaymentMethod( {
        name: 'dkpay',
        ariaLabel: settings.title || 'DKPay',
        label: el( Label ),
        content: el( Content ),
        edit: el( Content ),
        canMakePayment: function() {
            return !! ( settings.methods && settings.methods.length );
        },
        supports: {
            features: [ 'products' ]
        }
    } );
}() );
