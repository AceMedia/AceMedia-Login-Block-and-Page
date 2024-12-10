/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { TextControl, PanelBody, RadioControl } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Block metadata
 */
registerBlockType('acemedia/login-block', {
    title: __('Login Block', 'acemedia-login-block'),
    icon: 'lock',
    category: 'common',
    attributes: {
        labelRemember: {
            type: 'string',
            default: __('Remember Me', 'acemedia-login-block'),
        },
        labelLogIn: {
            type: 'string',
            default: __('Log In', 'acemedia-login-block'),
        },
        templateType: {
            type: 'string',
            default: 'full', // Default to the full template
        },
    },

    edit: ({ attributes, setAttributes }) => {
        const { labelRemember, labelLogIn, templateType } = attributes;

        // Full template
        const FULL_TEMPLATE = [
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                    ['core/paragraph', { content: __('<label for="log"><strong>Username:</strong></label>', 'acemedia-login-block'), align: 'right' }],
                ]],
                ['core/column', {}, [
                    ['acemedia/username-block'],
                ]],
            ]],
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                    ['core/paragraph', { content: __('<label for="pwd"><strong>Password:</strong></label>', 'acemedia-login-block'), align: 'right' }],
                ]],
                ['core/column', { style: { spacing: { margin: '0px' } } }, [
                    ['acemedia/password-block'],
                ]],
            ]],
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                ]],
                ['core/column', {}, [
                    ['acemedia/remember-me-block', { label: labelRemember }],
                    ['core/buttons', {}, [
                        ['core/button', {
                            text: labelLogIn,
                            className: 'button',
                        }]
                    ]]
                ]],
            ]],
        ];

        // Minimal template
        const MINIMAL_TEMPLATE = [
            ['core/group',
                { layout: { type: 'flex', flexWrap: 'nowrap', justifyContent: 'space-between' } }, [
                ['acemedia/username-block', {}],
                ['acemedia/password-block', {}],
                ['core/button', {
                    text: labelLogIn,
                    className: 'button',
                }],
            ]],
        ];
        

        // Select the appropriate template based on templateType
        const selectedTemplate = templateType === 'full' ? FULL_TEMPLATE : MINIMAL_TEMPLATE;

        return (
            <div {...useBlockProps()}>
                <InspectorControls>
                    <PanelBody title={__('Login Form Settings', 'acemedia-login-block')}>
                        <RadioControl
                            label={__('Template Type', 'acemedia-login-block')}
                            selected={templateType}
                            options={[
                                { label: __('Full Template', 'acemedia-login-block'), value: 'full' },
                                { label: __('Minimal Template', 'acemedia-login-block'), value: 'minimal' },
                            ]}
                            onChange={(value) => setAttributes({ templateType: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <form className="wp-block-login-form" method="post">
                    <InnerBlocks template={selectedTemplate} templateLock="all" />
                </form>
            </div>
        );
    },

    save: ({ attributes }) => {
        return (
            <div className="wp-block-login-form">
                <form action={aceLoginBlock.loginUrl} method="post">
                    <InnerBlocks.Content />
                    <button type="submit" style={{ display: 'none' }}>Submit</button>
                </form>
            </div>
        );
    },
});
