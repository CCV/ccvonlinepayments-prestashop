<select id="issuer_{$method}">
    {if $method == "ideal"}
        <option>{l s='Choose your bank...' d='Modules.Ccvonlinepayments.Shop'}</option>
    {else}
        <option></option>
    {/if}

    {foreach $issuers as $issuer}
        <option value="{$issuer->getId()}">{$issuer->getDescription()}</option>
    {/foreach}
</select>


<script>
    (function () {
        document.getElementById("issuer_{$method}").addEventListener("change", function() {
                document.querySelector("input[name=issuer_{$method}]").value =
                        document.getElementById("issuer_{$method}").value;
        });
    }());
</script>
