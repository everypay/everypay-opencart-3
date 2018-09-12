var installments = [];
$(function() {
    var table = $('#installment-table').html();
    Mustache.parse(table);
    var renderedTable = Mustache.render(table, {});
    $('#installments').html(renderedTable);

    var input = $('#everypay-installments').val();
    if (input) {
        installments = JSON.parse(input);
        createElements();
    }

    $('#add-installment').click(function (e) {
        e.preventDefault();
        var maxRows = maxElementIndex();

        var row = $('#installment-row').html();
        Mustache.parse(row);
        var element = {id: maxRows, from: 0, to: 100, max: 12};
        var renderedRow = Mustache.render(row, element);
        $row = $(renderedRow);
        addInstallment($row);
        $row.find('input').change(function (e){
            addInstallment($(this).parent().parent());
        });
        $('#installments table tbody').append($row);
        $row.find('.remove-installment').click(function (e){
            e.preventDefault();
            removeInstallment($(this).parent().parent());
            $(this).parent().parent().remove();
        });
    });
});

var addInstallment = function (row) {
    var element = {
        id: row.attr('data-id'),
        from: row.find('input[name$="from"]').val(),
        to:  row.find('input[name$="to"]').val(),
        max:  row.find('input[name^="max"]').val(),
    }

    index = elementExists(element.id);
    if (false !== index) {
        installments[index] = element;
    } else {
        installments.push(element);
    }
    $('#everypay-installments').val(JSON.stringify(installments));
};

var removeInstallment = function (row) {
    var index = false;
    var id = row.attr('data-id');
    for (var i = 0, l = installments.length; i < l; i++) {
        if (installments[i].id == id) {
            index = i;
        }
    }

    if (false !== index) {
        installments.splice(index, 1);
    }
    $('#everypay-installments').val(JSON.stringify(installments));
};

var elementExists = function (id) {
    for (var i = 0, l = installments.length; i < l; i++) {
        if (installments[i].id == id) {
            return i;
        }
    }

    return false;
}

var maxElementIndex = function (row) {
    var length = $('#installments table tbody tr').length;
    if (0 == length) {
        return 1;
    }

    length = $('#installments table tbody tr:last').attr('data-id');
    length = parseInt(length);

    return length + 1;
}

var createElements = function () {
    var row = $('#installment-row').html();
    Mustache.parse(row);
    for (var i = 0, l = installments.length; i < l; i++) {
        var element = installments[i];
        var renderedRow = Mustache.render(row, element);
        $row = $(renderedRow);
        $row.find('input').change(function (e){
            addInstallment($(this).parent().parent());
        });
        $('#installments table tbody').append($row);
        $row.find('.remove-installment').click(function (e){
            e.preventDefault();
            removeInstallment($(this).parent().parent());
            $(this).parent().parent().remove();
        });
    }
}
