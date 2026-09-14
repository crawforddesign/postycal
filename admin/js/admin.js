/**
 * PostyCal Admin JavaScript
 *
 * Tab switching, schedule / post-type / taxonomy CRUD modals,
 * and AJAX interactions.
 *
 * @package PostyCal
 * @since 2.1.0
 */

( function( $ ) {
    'use strict';

    const PostyCalAdmin = {

        config:          window.postycal || {},
        schedules:       [],
        postTypes:       [],
        taxonomies:      [],
        postTypeChoices: [],
        taxCache:        {},
        termsCache:      {},

        init: function() {
            this.schedules       = this.config.schedules       || [];
            this.postTypes       = this.config.postTypes       || [];
            this.taxonomies      = this.config.taxonomies      || [];
            this.postTypeChoices = this.config.postTypeChoices || [];
            this.bindEvents();
        },

        // -----------------------------------------------------------------
        // Event binding
        // -----------------------------------------------------------------

        bindEvents: function() {
            const self = this;

            // Tab switching — delegated so it works even if the nav is re-rendered.
            $( document ).on( 'click', '.postycal-tab-btn', function( e ) {
                e.preventDefault();
                self.switchTab( $( this ).data( 'tab' ) );
            } );

            // ---- Schedule modal ----
            $( '#postycal-add-schedule' ).on( 'click', function() { self.openScheduleAdd(); } );
            $( '#postycal-cancel' ).on( 'click', function() { self.closeModal( '#postycal-modal' ); } );
            $( '#postycal-modal .postycal-modal-backdrop' ).on( 'click', function() { self.closeModal( '#postycal-modal' ); } );
            $( '#postycal-schedule-form' ).on( 'submit', function( e ) { self.submitSchedule( e ); } );
            $( '#postycal-post-type' ).on( 'change', function() { self.onSchedulePostTypeChange(); } );
            $( '#postycal-taxonomy' ).on( 'change', function() { self.onScheduleTaxonomyChange(); } );
            $( '#postycal-trigger-cron' ).on( 'click', function( e ) { self.triggerCron( e ); } );
            $( document ).on( 'click', '.postycal-edit-schedule',   function( e ) { self.openScheduleEdit( e ); } );
            $( document ).on( 'click', '.postycal-delete-schedule', function( e ) { self.deleteSchedule( e ); } );

            // ---- Post Type modal ----
            $( '#postycal-add-post-type' ).on( 'click', function() { self.openCptAdd(); } );
            $( '#postycal-cpt-cancel' ).on( 'click', function() { self.closeModal( '#postycal-cpt-modal' ); } );
            $( '#postycal-cpt-modal .postycal-modal-backdrop' ).on( 'click', function() { self.closeModal( '#postycal-cpt-modal' ); } );
            $( '#postycal-cpt-form' ).on( 'submit', function( e ) { self.submitCpt( e ); } );
            $( '#postycal-cpt-name' ).on( 'input', function() { self.autoSlug( $( this ).val(), '#postycal-cpt-slug', 20 ); } );
            $( '#postycal-cpt-slug' ).on( 'input', function() { $( this ).data( 'manual', true ); } );
            $( document ).on( 'click', '.postycal-edit-post-type',   function( e ) { self.openCptEdit( e ); } );
            $( document ).on( 'click', '.postycal-delete-post-type', function( e ) { self.deleteCpt( e ); } );

            // ---- Taxonomy modal ----
            $( '#postycal-add-taxonomy' ).on( 'click', function() { self.openTaxAdd(); } );
            $( '#postycal-tax-cancel' ).on( 'click', function() { self.closeModal( '#postycal-tax-modal' ); } );
            $( '#postycal-tax-modal .postycal-modal-backdrop' ).on( 'click', function() { self.closeModal( '#postycal-tax-modal' ); } );
            $( '#postycal-tax-form' ).on( 'submit', function( e ) { self.submitTax( e ); } );
            $( '#postycal-tax-name' ).on( 'input', function() { self.autoSlug( $( this ).val(), '#postycal-tax-slug', 32 ); } );
            $( '#postycal-tax-slug' ).on( 'input', function() { $( this ).data( 'manual', true ); } );
            $( document ).on( 'click', '.postycal-edit-taxonomy',   function( e ) { self.openTaxEdit( e ); } );
            $( document ).on( 'click', '.postycal-delete-taxonomy', function( e ) { self.deleteTax( e ); } );

            // Escape key.
            $( document ).on( 'keydown', function( e ) {
                if ( e.key === 'Escape' ) {
                    $( '.postycal-modal:visible' ).each( function() {
                        self.closeModal( '#' + $( this ).attr( 'id' ) );
                    } );
                }
            } );
        },

        // -----------------------------------------------------------------
        // Tab switching
        // -----------------------------------------------------------------

        switchTab: function( tab ) {
            $( '.postycal-tab-btn' ).removeClass( 'postycal-active' );
            $( '.postycal-tab-btn[data-tab="' + tab + '"]' ).addClass( 'postycal-active' );
            $( '.postycal-tab-panel' ).hide();
            $( '#postycal-tab-' + tab ).show();
        },

        // -----------------------------------------------------------------
        // Modal helpers
        // -----------------------------------------------------------------

        closeModal: function( selector ) {
            $( selector ).hide();
            this.clearModalError( selector + ' .postycal-modal-error' );
        },

        showModalError: function( modalSelector, message ) {
            const $err = $( modalSelector + ' .postycal-modal-error' );
            $err.find( 'p' ).text( message );
            $err.show();
            // Scroll the modal content to the top so the error is visible.
            $( modalSelector + ' .postycal-modal-content' ).scrollTop( 0 );
        },

        clearModalError: function( selector ) {
            $( selector ).hide().find( 'p' ).text( '' );
        },

        /**
         * Pull the server's error message out of a failed request.
         *
         * Permission and nonce failures are answered with a 403, which jQuery
         * routes to .fail() — without this the user would only ever see the
         * generic fallback and never learn that their session had expired.
         */
        failMessage: function( jqXHR, fallback ) {
            if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
                return jqXHR.responseJSON.data.message;
            }
            return fallback;
        },

        // -----------------------------------------------------------------
        // Post type choice lists
        // -----------------------------------------------------------------

        /**
         * Repopulate the post-type pickers in the Schedule and Taxonomy
         * modals after a post type is created or deleted, so a new type is
         * usable without reloading the page. Current selections are kept.
         */
        rebuildPostTypeChoices: function() {
            const self    = this;
            const choices = this.postTypeChoices || [];

            const $select  = $( '#postycal-post-type' );
            const selected = $select.val();
            let   options  = '<option value="">' + this.escapeHtml( this.config.i18n.selectPostType ) + '</option>';

            choices.forEach( function( c ) {
                options += '<option value="' + self.escapeHtml( c.slug ) + '">' + self.escapeHtml( c.label ) + '</option>';
            } );

            $select.html( options ).val( selected );

            const $fieldset = $( '#postycal-tax-post-types' );
            const checked   = [];
            $fieldset.find( 'input:checked' ).each( function() { checked.push( $( this ).val() ); } );

            let boxes = '';
            choices.forEach( function( c ) {
                const isOn = checked.indexOf( c.slug ) !== -1 ? ' checked' : '';
                boxes += '<label><input type="checkbox" name="post_types[]" value="' + self.escapeHtml( c.slug ) + '"' + isOn + '> ' +
                         self.escapeHtml( c.label ) + ' <code>(' + self.escapeHtml( c.slug ) + ')</code></label><br>';
            } );

            $fieldset.html( boxes );
        },

        // -----------------------------------------------------------------
        // Slug auto-generation
        // -----------------------------------------------------------------

        autoSlug: function( name, slugSelector, maxLen ) {
            const $slug = $( slugSelector );
            if ( $slug.data( 'manual' ) ) return;
            const slug = name.toLowerCase()
                             .replace( /[^a-z0-9]+/g, '_' )
                             .replace( /^_+|_+$/g, '' )
                             .substring( 0, maxLen );
            $slug.val( slug );
        },

        // -----------------------------------------------------------------
        // Schedule modal
        // -----------------------------------------------------------------

        openScheduleAdd: function() {
            this.resetScheduleForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.addSchedule );
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).trigger( 'focus' );
        },

        openScheduleEdit: function( e ) {
            e.preventDefault();
            const self     = this;
            const index    = parseInt( $( e.currentTarget ).data( 'index' ), 10 );
            const schedule = this.schedules[ index ];
            if ( ! schedule ) return;

            this.resetScheduleForm();
            $( '#postycal-modal-title' ).text( this.config.i18n.editSchedule );
            $( '#postycal-schedule-index' ).val( index );
            $( '#postycal-name' ).val( schedule.name );
            $( '#postycal-post-type' ).val( schedule.post_type );
            $( '#postycal-use-time' ).prop( 'checked', !! schedule.use_time );

            this.loadTaxonomiesForPostType( schedule.post_type, function() {
                $( '#postycal-taxonomy' ).val( schedule.taxonomy );
                self.loadTermsForTaxonomy( schedule.taxonomy, function() {
                    $( '#postycal-upcoming-term' ).val( schedule.upcoming_term );
                    $( '#postycal-active-term' ).val( schedule.active_term );
                    $( '#postycal-past-term' ).val( schedule.past_term );
                } );
            } );

            $( '#postycal-modal' ).show();
            $( '#postycal-name' ).trigger( 'focus' );
        },

        resetScheduleForm: function() {
            $( '#postycal-schedule-form' )[ 0 ].reset();
            $( '#postycal-schedule-index' ).val( '' );
            $( '#postycal-use-time' ).prop( 'checked', false );
            this.clearModalError( '#postycal-modal .postycal-modal-error' );
            const taxPH  = '<option value="">' + this.config.i18n.selectPostTypeFirst + '</option>';
            const termPH = '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>';
            $( '#postycal-taxonomy' ).html( taxPH );
            $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( termPH );
        },

        onSchedulePostTypeChange: function() {
            const postType = $( '#postycal-post-type' ).val();
            $( '#postycal-taxonomy' ).html( '<option value="">' + this.config.i18n.selectPostTypeFirst + '</option>' );
            $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
            if ( postType ) this.loadTaxonomiesForPostType( postType );
        },

        onScheduleTaxonomyChange: function() {
            const taxonomy = $( '#postycal-taxonomy' ).val();
            $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( '<option value="">' + this.config.i18n.selectTaxonomyFirst + '</option>' );
            if ( taxonomy ) this.loadTermsForTaxonomy( taxonomy );
        },

        loadTaxonomiesForPostType: function( postType, callback ) {
            const self    = this;
            const $select = $( '#postycal-taxonomy' );
            const $spin   = $( '#postycal-tax-spinner' );

            if ( this.taxCache[ postType ] ) {
                this.populateTaxonomyDropdown( this.taxCache[ postType ] );
                if ( callback ) callback();
                return;
            }

            $select.html( '<option value="">' + this.config.i18n.loading + '</option>' );
            $spin.addClass( 'is-active' );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_get_post_type_taxonomies',
                nonce:  this.config.nonce,
                post_type: postType
            } )
            .done( function( r ) {
                if ( r.success ) {
                    self.taxCache[ postType ] = r.data.taxonomies;
                    self.populateTaxonomyDropdown( r.data.taxonomies );
                } else {
                    $select.html( '<option value="">' + self.config.i18n.noTaxonomiesFound + '</option>' );
                }
                if ( callback ) callback();
            } )
            .fail( function() {
                $select.html( '<option value="">' + self.config.i18n.errorLoadingTax + '</option>' );
            } )
            .always( function() { $spin.removeClass( 'is-active' ); } );
        },

        populateTaxonomyDropdown: function( taxonomies ) {
            const self = this;
            let html   = '<option value="">' + this.config.i18n.selectTaxonomy + '</option>';
            if ( ! taxonomies || ! taxonomies.length ) {
                html = '<option value="">' + this.config.i18n.noTaxonomiesFound + '</option>';
            } else {
                taxonomies.forEach( function( t ) {
                    html += '<option value="' + self.escapeHtml( t.slug ) + '">' + self.escapeHtml( t.name ) + '</option>';
                } );
            }
            $( '#postycal-taxonomy' ).html( html );
        },

        loadTermsForTaxonomy: function( taxonomy, callback ) {
            const self  = this;
            const $spin = $( '#postycal-terms-spinner' );
            const loading = '<option value="">' + this.config.i18n.loading + '</option>';

            if ( this.termsCache[ taxonomy ] ) {
                this.populateTermDropdowns( this.termsCache[ taxonomy ] );
                if ( callback ) callback();
                return;
            }

            $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( loading );
            $spin.addClass( 'is-active' );

            $.post( this.config.ajaxUrl, {
                action:   'postycal_get_taxonomy_terms',
                nonce:    this.config.nonce,
                taxonomy: taxonomy
            } )
            .done( function( r ) {
                if ( r.success ) {
                    self.termsCache[ taxonomy ] = r.data.terms;
                    self.populateTermDropdowns( r.data.terms );
                } else {
                    const err = '<option value="">' + self.config.i18n.noTermsFound + '</option>';
                    $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( err );
                }
                if ( callback ) callback();
            } )
            .fail( function() {
                const err = '<option value="">' + self.config.i18n.errorLoadingTerms + '</option>';
                $( '#postycal-upcoming-term, #postycal-active-term, #postycal-past-term' ).html( err );
            } )
            .always( function() { $spin.removeClass( 'is-active' ); } );
        },

        populateTermDropdowns: function( terms ) {
            const self = this;
            let html   = '<option value="">' + this.config.i18n.selectTerm + '</option>';
            if ( ! terms || ! terms.length ) {
                html = '<option value="">' + this.config.i18n.noTermsFound + '</option>';
            } else {
                terms.forEach( function( t ) {
                    html += '<option value="' + self.escapeHtml( t.slug ) + '">' + self.escapeHtml( t.name ) + ' (' + self.escapeHtml( t.slug ) + ')</option>';
                } );
            }
            $( '#postycal-upcoming-term' ).html( html );
            $( '#postycal-active-term' ).html( html );
            $( '#postycal-past-term' ).html( html );
        },

        submitSchedule: function( e ) {
            e.preventDefault();
            const self = this;
            const $btn = $( '#postycal-schedule-form button[type="submit"]' );
            this.clearModalError( '#postycal-modal .postycal-modal-error' );
            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, {
                action:        'postycal_save_schedule',
                nonce:         this.config.nonce,
                index:         $( '#postycal-schedule-index' ).val(),
                name:          $( '#postycal-name' ).val(),
                post_type:     $( '#postycal-post-type' ).val(),
                taxonomy:      $( '#postycal-taxonomy' ).val(),
                upcoming_term: $( '#postycal-upcoming-term' ).val(),
                active_term:   $( '#postycal-active-term' ).val(),
                past_term:     $( '#postycal-past-term' ).val(),
                use_time:      $( '#postycal-use-time' ).is( ':checked' ) ? '1' : ''
            } )
            .done( function( r ) {
                if ( r.success ) {
                    self.schedules = r.data.schedules;
                    self.refreshSchedulesTable();
                    self.closeModal( '#postycal-modal' );
                    self.showNotice( 'success', r.data.message );
                } else {
                    self.showModalError( '#postycal-modal', r.data.message || self.config.i18n.saveError );
                }
            } )
            .fail( function( jqXHR ) { self.showModalError( '#postycal-modal', self.failMessage( jqXHR, self.config.i18n.saveError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        deleteSchedule: function( e ) {
            e.preventDefault();
            if ( ! confirm( this.config.i18n.confirmDelete ) ) return;
            const self = this;
            const $btn = $( e.currentTarget );
            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, {
                action: 'postycal_delete_schedule',
                nonce:  this.config.nonce,
                index:  $btn.data( 'index' )
            } )
            .done( function( r ) {
                if ( r.success ) { self.schedules = r.data.schedules; self.refreshSchedulesTable(); self.showNotice( 'success', r.data.message ); }
                else { self.showNotice( 'error', r.data.message || self.config.i18n.deleteError ); }
            } )
            .fail( function( jqXHR ) { self.showNotice( 'error', self.failMessage( jqXHR, self.config.i18n.deleteError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        triggerCron: function( e ) {
            e.preventDefault();
            const self = this;
            const $btn = $( e.currentTarget );
            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, { action: 'postycal_trigger_cron', nonce: this.config.nonce } )
            .done( function( r ) {
                if ( r.success ) {
                    let msg = r.data.message;
                    if ( r.data.results ) {
                        const parts = [];
                        $.each( r.data.results, function( name, counts ) {
                            parts.push(
                                name + ': ' + counts.published + ' ' + self.config.i18n.published +
                                ', ' + counts.expired + ' ' + self.config.i18n.expired +
                                ', ' + ( counts.pinned || 0 ) + ' ' + self.config.i18n.pinned
                            );
                        } );
                        if ( parts.length ) msg += ' (' + parts.join( ' | ' ) + ')';
                    }
                    self.showNotice( 'success', msg );
                } else { self.showNotice( 'error', r.data.message || self.config.i18n.triggerError ); }
            } )
            .fail( function( jqXHR ) { self.showNotice( 'error', self.failMessage( jqXHR, self.config.i18n.triggerError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        // -----------------------------------------------------------------
        // Post Type modal
        // -----------------------------------------------------------------

        openCptAdd: function() {
            this.resetCptForm();
            $( '#postycal-cpt-modal-title' ).text( this.config.i18n.addPostType );
            $( '#postycal-cpt-slug' ).removeData( 'manual' ).prop( 'readonly', false );
            $( '#postycal-cpt-modal' ).show();
            $( '#postycal-cpt-name' ).trigger( 'focus' );
        },

        openCptEdit: function( e ) {
            e.preventDefault();
            const index = parseInt( $( e.currentTarget ).data( 'index' ), 10 );
            const pt    = this.postTypes[ index ];
            if ( ! pt ) return;

            this.resetCptForm();
            $( '#postycal-cpt-modal-title' ).text( this.config.i18n.editPostType );
            $( '#postycal-cpt-index' ).val( index );
            $( '#postycal-cpt-name' ).val( pt.name );
            $( '#postycal-cpt-plural' ).val( pt.plural );
            // The slug keys every post of this type; the server ignores changes to it.
            $( '#postycal-cpt-slug' ).val( pt.slug ).data( 'manual', true ).prop( 'readonly', true );
            $( '#postycal-cpt-description' ).val( pt.description || '' );
            $( '#postycal-cpt-has-archive' ).prop( 'checked', !! pt.has_archive );
            $( '#postycal-cpt-show-in-rest' ).prop( 'checked', pt.show_in_rest !== false );
            $( '#postycal-cpt-menu-icon' ).val( pt.menu_icon || '' );

            const supports = pt.supports || [];
            $( '.postycal-cpt-supports' ).each( function() {
                $( this ).prop( 'checked', supports.indexOf( $( this ).val() ) !== -1 );
            } );

            $( '#postycal-cpt-modal' ).show();
            $( '#postycal-cpt-name' ).trigger( 'focus' );
        },

        resetCptForm: function() {
            $( '#postycal-cpt-form' )[ 0 ].reset();
            $( '#postycal-cpt-index' ).val( '' );
            $( '#postycal-cpt-show-in-rest' ).prop( 'checked', true );
            $( '.postycal-cpt-supports[value="editor"]' ).prop( 'checked', true );
            this.clearModalError( '#postycal-cpt-modal .postycal-modal-error' );
        },

        submitCpt: function( e ) {
            e.preventDefault();
            const self     = this;
            const $btn     = $( '#postycal-cpt-form button[type="submit"]' );
            const name     = $.trim( $( '#postycal-cpt-name' ).val() );
            const slug     = $.trim( $( '#postycal-cpt-slug' ).val() );

            this.clearModalError( '#postycal-cpt-modal .postycal-modal-error' );

            // JS-side validation.
            if ( ! name ) { this.showModalError( '#postycal-cpt-modal', 'Name is required.' ); return; }
            if ( ! slug ) { this.showModalError( '#postycal-cpt-modal', 'Slug is required.' ); return; }
            if ( ! /^[a-z0-9_]{1,20}$/.test( slug ) ) {
                this.showModalError( '#postycal-cpt-modal', 'Slug must be lowercase letters, numbers, and underscores only (max 20 characters).' );
                return;
            }

            const supports = [];
            $( '.postycal-cpt-supports:checked' ).each( function() { supports.push( $( this ).val() ); } );

            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, {
                action:       'postycal_save_post_type',
                nonce:        this.config.nonce,
                index:        $( '#postycal-cpt-index' ).val(),
                name:         name,
                plural:       $.trim( $( '#postycal-cpt-plural' ).val() ),
                slug:         slug,
                description:  $( '#postycal-cpt-description' ).val(),
                has_archive:  $( '#postycal-cpt-has-archive' ).is( ':checked' ) ? '1' : '',
                show_in_rest: $( '#postycal-cpt-show-in-rest' ).is( ':checked' ) ? '1' : '',
                supports:     supports,
                menu_icon:    $( '#postycal-cpt-menu-icon' ).val()
            } )
            .done( function( r ) {
                if ( r.success ) {
                    self.postTypes       = r.data.postTypes;
                    self.postTypeChoices = r.data.postTypeChoices || self.postTypeChoices;
                    self.taxCache        = {};
                    self.refreshPostTypesTable();
                    self.rebuildPostTypeChoices();
                    self.closeModal( '#postycal-cpt-modal' );
                    self.showNotice( 'success', r.data.message );
                } else {
                    self.showModalError( '#postycal-cpt-modal', r.data.message || self.config.i18n.saveError );
                }
            } )
            .fail( function( jqXHR ) { self.showModalError( '#postycal-cpt-modal', self.failMessage( jqXHR, self.config.i18n.saveError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        deleteCpt: function( e ) {
            e.preventDefault();
            if ( ! confirm( this.config.i18n.confirmDelete ) ) return;
            const self = this;
            const $btn = $( e.currentTarget );
            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, { action: 'postycal_delete_post_type', nonce: this.config.nonce, index: $btn.data( 'index' ) } )
            .done( function( r ) {
                if ( r.success ) {
                    self.postTypes       = r.data.postTypes;
                    self.postTypeChoices = r.data.postTypeChoices || self.postTypeChoices;
                    self.taxCache        = {};
                    self.refreshPostTypesTable();
                    self.rebuildPostTypeChoices();
                    self.showNotice( 'success', r.data.message );
                }
                else { self.showNotice( 'error', r.data.message || self.config.i18n.deleteError ); }
            } )
            .fail( function( jqXHR ) { self.showNotice( 'error', self.failMessage( jqXHR, self.config.i18n.deleteError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        // -----------------------------------------------------------------
        // Taxonomy modal
        // -----------------------------------------------------------------

        openTaxAdd: function() {
            this.resetTaxForm( true );
            $( '#postycal-tax-modal-title' ).text( this.config.i18n.addTaxonomy );
            $( '#postycal-tax-slug' ).removeData( 'manual' ).prop( 'readonly', false );
            $( '#postycal-tax-modal' ).show();
            $( '#postycal-tax-name' ).trigger( 'focus' );
        },

        openTaxEdit: function( e ) {
            e.preventDefault();
            const index = parseInt( $( e.currentTarget ).data( 'index' ), 10 );
            const tax   = this.taxonomies[ index ];
            if ( ! tax ) return;

            this.resetTaxForm( false );
            $( '#postycal-tax-modal-title' ).text( this.config.i18n.editTaxonomy );
            $( '#postycal-tax-index' ).val( index );
            $( '#postycal-tax-name' ).val( tax.name );
            $( '#postycal-tax-plural' ).val( tax.plural );
            // The slug keys every term in this taxonomy; the server ignores changes to it.
            $( '#postycal-tax-slug' ).val( tax.slug ).data( 'manual', true ).prop( 'readonly', true );
            $( '#postycal-tax-hierarchical' ).prop( 'checked', !! tax.hierarchical );
            $( '#postycal-tax-show-in-rest' ).prop( 'checked', tax.show_in_rest !== false );

            const assigned = tax.post_types || [];
            $( '#postycal-tax-post-types input[type="checkbox"]' ).each( function() {
                $( this ).prop( 'checked', assigned.indexOf( $( this ).val() ) !== -1 );
            } );

            $( '#postycal-tax-modal' ).show();
            $( '#postycal-tax-name' ).trigger( 'focus' );
        },

        resetTaxForm: function( isNew ) {
            $( '#postycal-tax-form' )[ 0 ].reset();
            $( '#postycal-tax-index' ).val( '' );
            $( '#postycal-tax-show-in-rest' ).prop( 'checked', true );
            $( '#postycal-tax-post-types input[type="checkbox"]' ).prop( 'checked', false );
            this.clearModalError( '#postycal-tax-modal .postycal-modal-error' );
            // Seed terms section only shown for new taxonomies.
            if ( isNew ) {
                $( '#postycal-tax-seed-row' ).show();
            } else {
                $( '#postycal-tax-seed-row' ).hide();
            }
        },

        submitTax: function( e ) {
            e.preventDefault();
            const self = this;
            const $btn = $( '#postycal-tax-form button[type="submit"]' );
            const name = $.trim( $( '#postycal-tax-name' ).val() );
            const slug = $.trim( $( '#postycal-tax-slug' ).val() );

            this.clearModalError( '#postycal-tax-modal .postycal-modal-error' );

            // JS-side validation.
            if ( ! name ) { this.showModalError( '#postycal-tax-modal', 'Name is required.' ); return; }
            if ( ! slug ) { this.showModalError( '#postycal-tax-modal', 'Slug is required.' ); return; }
            if ( ! /^[a-z0-9_]{1,32}$/.test( slug ) ) {
                this.showModalError( '#postycal-tax-modal', 'Slug must be lowercase letters, numbers, and underscores only (max 32 characters).' );
                return;
            }

            const post_types = [];
            $( '#postycal-tax-post-types input:checked' ).each( function() { post_types.push( $( this ).val() ); } );

            if ( ! post_types.length ) {
                this.showModalError( '#postycal-tax-modal', 'Please select at least one post type to assign this taxonomy to.' );
                return;
            }

            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, {
                action:       'postycal_save_taxonomy',
                nonce:        this.config.nonce,
                index:        $( '#postycal-tax-index' ).val(),
                name:         name,
                plural:       $.trim( $( '#postycal-tax-plural' ).val() ),
                slug:         slug,
                hierarchical: $( '#postycal-tax-hierarchical' ).is( ':checked' ) ? '1' : '',
                show_in_rest: $( '#postycal-tax-show-in-rest' ).is( ':checked' ) ? '1' : '',
                post_types:   post_types,
                seed_upcoming: $( '[name="seed_upcoming"]' ).val(),
                seed_active:   $( '[name="seed_active"]' ).val(),
                seed_past:     $( '[name="seed_past"]' ).val()
            } )
            .done( function( r ) {
                if ( r.success ) {
                    self.taxonomies  = r.data.taxonomies;
                    self.taxCache    = {};
                    self.termsCache  = {};
                    self.refreshTaxonomiesTable();
                    self.closeModal( '#postycal-tax-modal' );
                    self.showNotice( 'success', r.data.message );
                } else {
                    self.showModalError( '#postycal-tax-modal', r.data.message || self.config.i18n.saveError );
                }
            } )
            .fail( function( jqXHR ) { self.showModalError( '#postycal-tax-modal', self.failMessage( jqXHR, self.config.i18n.saveError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        deleteTax: function( e ) {
            e.preventDefault();
            if ( ! confirm( this.config.i18n.confirmDelete ) ) return;
            const self = this;
            const $btn = $( e.currentTarget );
            this.setLoading( $btn, true );

            $.post( this.config.ajaxUrl, { action: 'postycal_delete_taxonomy', nonce: this.config.nonce, index: $btn.data( 'index' ) } )
            .done( function( r ) {
                if ( r.success ) { self.taxonomies = r.data.taxonomies; self.taxCache = {}; self.termsCache = {}; self.refreshTaxonomiesTable(); self.showNotice( 'success', r.data.message ); }
                else { self.showNotice( 'error', r.data.message || self.config.i18n.deleteError ); }
            } )
            .fail( function( jqXHR ) { self.showNotice( 'error', self.failMessage( jqXHR, self.config.i18n.deleteError ) ); } )
            .always( function() { self.setLoading( $btn, false ); } );
        },

        // -----------------------------------------------------------------
        // Table refresh
        // -----------------------------------------------------------------

        refreshSchedulesTable: function() {
            const self   = this;
            const $tbody = $( '#postycal-schedules-table tbody' );
            $tbody.empty();

            if ( ! this.schedules.length ) {
                $tbody.append( '<tr class="no-items"><td colspan="7">' + this.escapeHtml( this.config.i18n.noSchedules ) + '</td></tr>' );
                $( '#postycal-trigger-cron' ).hide();
                return;
            }
            $( '#postycal-trigger-cron' ).show();
            this.schedules.forEach( function( s, i ) {
                const t = s.use_time ? ' (time-aware)' : '';
                $tbody.append(
                    '<tr data-index="' + i + '">' +
                    '<td>' + self.escapeHtml( s.name ) + '</td>' +
                    '<td>' + self.escapeHtml( s.post_type ) + '</td>' +
                    '<td>' + self.escapeHtml( s.taxonomy + t ) + '</td>' +
                    '<td>' + self.escapeHtml( s.upcoming_term ) + '</td>' +
                    '<td>' + self.escapeHtml( s.active_term ) + '</td>' +
                    '<td>' + self.escapeHtml( s.past_term ) + '</td>' +
                    '<td><button type="button" class="button postycal-edit-schedule" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.editButton ) + '</button> ' +
                    '<button type="button" class="button postycal-delete-schedule" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.deleteButton ) + '</button></td>' +
                    '</tr>'
                );
            } );
        },

        refreshPostTypesTable: function() {
            const self   = this;
            const $tbody = $( '#postycal-post-types-table tbody' );
            $tbody.empty();

            if ( ! this.postTypes.length ) {
                $tbody.append( '<tr class="no-items"><td colspan="6">' + this.escapeHtml( this.config.i18n.noPostTypes ) + '</td></tr>' );
                return;
            }
            this.postTypes.forEach( function( pt, i ) {
                $tbody.append(
                    '<tr data-index="' + i + '">' +
                    '<td>' + self.escapeHtml( pt.name ) + ' <span class="description">(' + self.escapeHtml( pt.plural ) + ')</span></td>' +
                    '<td><code>' + self.escapeHtml( pt.slug ) + '</code></td>' +
                    '<td>' + self.escapeHtml( ( pt.supports || [] ).join( ', ' ) ) + '</td>' +
                    '<td>' + ( pt.has_archive ? '&#10003;' : '&mdash;' ) + '</td>' +
                    '<td>' + ( pt.show_in_rest !== false ? '&#10003;' : '&mdash;' ) + '</td>' +
                    '<td><button type="button" class="button postycal-edit-post-type" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.editButton ) + '</button> ' +
                    '<button type="button" class="button postycal-delete-post-type" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.deleteButton ) + '</button></td>' +
                    '</tr>'
                );
            } );
        },

        refreshTaxonomiesTable: function() {
            const self   = this;
            const $tbody = $( '#postycal-taxonomies-table tbody' );
            $tbody.empty();

            if ( ! this.taxonomies.length ) {
                $tbody.append( '<tr class="no-items"><td colspan="6">' + this.escapeHtml( this.config.i18n.noTaxonomies ) + '</td></tr>' );
                return;
            }
            this.taxonomies.forEach( function( t, i ) {
                $tbody.append(
                    '<tr data-index="' + i + '">' +
                    '<td>' + self.escapeHtml( t.name ) + '</td>' +
                    '<td><code>' + self.escapeHtml( t.slug ) + '</code></td>' +
                    '<td>' + self.escapeHtml( ( t.post_types || [] ).join( ', ' ) ) + '</td>' +
                    '<td>' + ( t.hierarchical ? '&#10003;' : '&mdash;' ) + '</td>' +
                    '<td>' + ( t.show_in_rest !== false ? '&#10003;' : '&mdash;' ) + '</td>' +
                    '<td><button type="button" class="button postycal-edit-taxonomy" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.editButton ) + '</button> ' +
                    '<button type="button" class="button postycal-delete-taxonomy" data-index="' + i + '">' + self.escapeHtml( self.config.i18n.deleteButton ) + '</button></td>' +
                    '</tr>'
                );
            } );
        },

        // -----------------------------------------------------------------
        // Notices
        // -----------------------------------------------------------------

        showNotice: function( type, message ) {
            $( '.postycal-notice' ).remove();
            const $n = $(
                '<div class="notice notice-' + type + ' is-dismissible postycal-notice">' +
                '<p>' + this.escapeHtml( message ) + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
                '</div>'
            );
            $( '.wrap.postycal-settings h1' ).after( $n );
            setTimeout( function() { $n.fadeOut( 300, function() { $( this ).remove(); } ); }, 5000 );
            $n.find( '.notice-dismiss' ).on( 'click', function() { $( this ).closest( '.postycal-notice' ).remove(); } );
        },

        setLoading: function( $btn, loading ) {
            if ( loading ) {
                $btn.data( 'orig', $btn.text() ).prop( 'disabled', true ).text( this.config.i18n.processing );
            } else {
                $btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || $btn.text() );
            }
        },

        escapeHtml: function( str ) {
            if ( ! str && str !== 0 ) return '';
            const d = document.createElement( 'div' );
            d.appendChild( document.createTextNode( String( str ) ) );
            return d.innerHTML;
        }
    };

    $( document ).ready( function() { PostyCalAdmin.init(); } );

} )( jQuery );
