{extends file='page.tpl'}
{block name='page_content'}
    <h2>{l s='Payment pending' d='Modules.Ccvonlinepayments.Shop'}</h2>

    <style>
        .ccvonlinepayments-lds-roller-wrapper {
            text-align: center;
        }

        .ccvonlinepayments-lds-roller {
            display: inline-block;
            position: relative;
            width: 80px;
            height: 80px;
        }
        .ccvonlinepayments-lds-roller div {
            animation: ccvonlinepayments-lds-roller 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            transform-origin: 40px 40px;
        }
        .ccvonlinepayments-lds-roller div:after {
            content: " ";
            display: block;
            position: absolute;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #000;
            margin: -4px 0 0 -4px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(1) {
            animation-delay: -0.036s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(1):after {
            top: 63px;
            left: 63px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(2) {
            animation-delay: -0.072s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(2):after {
            top: 68px;
            left: 56px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(3) {
            animation-delay: -0.108s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(3):after {
            top: 71px;
            left: 48px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(4) {
            animation-delay: -0.144s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(4):after {
            top: 72px;
            left: 40px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(5) {
            animation-delay: -0.18s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(5):after {
            top: 71px;
            left: 32px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(6) {
            animation-delay: -0.216s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(6):after {
            top: 68px;
            left: 24px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(7) {
            animation-delay: -0.252s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(7):after {
            top: 63px;
            left: 17px;
        }
        .ccvonlinepayments-lds-roller div:nth-child(8) {
            animation-delay: -0.288s;
        }
        .ccvonlinepayments-lds-roller div:nth-child(8):after {
            top: 56px;
            left: 12px;
        }
        @keyframes ccvonlinepayments-lds-roller {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
    </style>

    <div class="ccvonlinepayments-lds-roller-wrapper">
        <div class="ccvonlinepayments-lds-roller"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>
    </div>

    <script>
        function pollCcvonlinepaymentsStatus() {
            var interval = 5000;
            var xhr = new XMLHttpRequest();
            xhr.open("GET", '{$pollEndpoint|escape:'javascript':'UTF-8' nofilter}');

            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if(data.status === 'success' || data.status === 'failed') {
                        window.location.href = window.location.href;
                        return;
                    }
                }catch(e){};

                setTimeout(pollCcvonlinepaymentsStatus, interval);
            }

            xhr.onerror = function() {
                setTimeout(pollCcvonlinepaymentsStatus, interval);;
            }

            xhr.send();
        }

        pollCcvonlinepaymentsStatus();
    </script>
{/block}
