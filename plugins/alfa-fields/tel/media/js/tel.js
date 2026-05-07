document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('input[data-alfa-tel]').forEach(function (input) {
        const iti = intlTelInput(input, {
            initialCountry: input.dataset.defaultRegion || 'GR',
            separateDialCode: true,
            strictMode: true,
            onlyCountries: input.dataset.allowedRegions
                ? input.dataset.allowedRegions.split(',').map(c => c.trim().toLowerCase())
                : [],
            allowedNumberTypes: input.dataset.requireMobile === '1'
                ? ["MOBILE"]
                : ["MOBILE", "FIXED_LINE"],
        });

        input.closest('form')?.addEventListener('submit', function (e) {
            input.value = iti.getNumber();
        });
    });

});


