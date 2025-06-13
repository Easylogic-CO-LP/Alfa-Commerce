(Joomla => {
    Joomla.typeHasChanged = element => {
        const url = new URL(window.location.href);
        const view = url.searchParams.get('view') || '';

        if(view == ''){
            console.error('View not found to call the controller reload');
            return;
        }

        // Show loading indicator
        document.body.appendChild(document.createElement('joomla-core-loader'));

        // Set the task dynamically
        document.querySelector('input[name=task]').value = `${view}.reload`;

        // Submit the form
        element.form.submit();
    };
})(Joomla);