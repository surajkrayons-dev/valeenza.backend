@extends('layouts.master')

@section('title', 'Delivery Rates')

@section('content')

    {{-- PAGE HEADER --}}
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">

                <h4 class="mb-sm-0 font-size-18">
                    Delivery Rates
                </h4>

                {{-- <div class="page-title-right">

                    <a href="{{ route('admin.delivery_rates.create.index') }}" class="btn btn-soft-info">

                        <i class="fas fa-plus"></i> Create

                    </a>

                </div> --}}

            </div>
        </div>
    </div>

    {{-- TABLE --}}
    <div class="row">

        <div class="col-12">

            <div class="card border">

                <div class="card-body">

                    <table id="data-table" class="table table-bordered dt-responsive nowrap w-100">

                        <thead>

                            <tr>

                                <th>Delivery Charge</th>

                                <th>Status</th>

                                <th class="text-center">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody></tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

@endsection

@section('script')

    <script>
        $(function() {

            let table = $('#data-table').DataTable({

                processing: true,

                serverSide: true,

                ajax: {

                    url: "{{ route('admin.delivery_rates.list') }}",

                    data: function(d) {

                        d.status = $('#status').val();

                    }

                },

                columns: [

                    {
                        data: 'delivery_charge',
                        name: 'delivery_charge',

                        render: function(data) {

                            return `$ ${parseFloat(data || 0).toFixed(2)}`;

                        }
                    },

                    {
                        data: null,
                        name: 'status',
                        className: 'text-center',

                        render: function(data, type, row) {

                            return `
                        <div class="square-switch">

                            <input
                                type="checkbox"
                                id="status-switch-${row.id}"
                                class="change-status"
                                switch="status"
                                data-id="${row.id}"
                                ${row.status == 1 ? 'checked' : ''}
                            />

                            <label
                                for="status-switch-${row.id}"
                                data-on-label="Yes"
                                data-off-label="No">
                            </label>

                        </div>
                    `;

                        }

                    },

                    {
                        data: null,

                        orderable: false,

                        searchable: false,

                        className: 'text-center',

                        render: function(data, type, row) {

                            return `
                        <a
                            href="{{ route('admin.delivery_rates.update.index') }}/${row.id}"
                            class="btn btn-soft-info">

                            <i class="bx bx-pencil"></i>

                        </a>

                        <button
                            type="button"
                            class="btn btn-soft-danger delete-entry"
                            data-href="{{ route('admin.delivery_rates.delete') }}/${row.id}">

                            <i class="bx bx-trash"></i>

                        </button>
                    `;

                        }

                    }

                ]

            });


            // CHANGE STATUS
            $(document).on('change', '.change-status', function() {

                let checkbox = $(this);

                let id = checkbox.data('id');

                let oldStatus = !checkbox.prop('checked');

                checkbox.prop('disabled', true);

                $.get(
                        `{{ route('admin.delivery_rates.change.status') }}/${id}`
                    )

                    .done(function() {

                        table.ajax.reload(null, false);

                    })

                    .fail(function() {

                        checkbox.prop('checked', oldStatus);

                        showToastr(
                            'error',
                            'Unable to update status'
                        );

                    })

                    .always(function() {

                        checkbox.prop('disabled', false);

                    });

            });


            // STATUS FILTER
            $('#status').on('change', function() {

                table.ajax.reload();

            });


            // RESET FILTER
            $('#reset-filter').on('click', function() {

                $('#status')
                    .val('')
                    .trigger('change');

            });

        });
    </script>

@endsection
