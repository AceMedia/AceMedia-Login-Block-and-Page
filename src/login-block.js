/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { TextControl, PanelBody, RadioControl, SelectControl, Notice } from '@wordpress/components';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Block metadata
 */
registerBlockType('acemedia/login-block', {
    apiVersion: 3,
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
                        <p>{__('Verification follows Settings → Login verification. Add a Verification block to choose its position in this layout.', 'acemedia-login-block')}</p>
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
                    <InnerBlocks template={selectedTemplate} templateLock={false} />
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

registerBlockType('acemedia/turnstile', {
    apiVersion: 3,
    title: __('Verification', 'acemedia-login-block'),
    icon: 'shield', category: 'widgets',
    attributes: { step: { type: 'string', default: 'login' } },
    supports: { html: false, reusable: true },
    edit: ({ attributes, setAttributes }) => <div {...useBlockProps()}>
        <InspectorControls><PanelBody title={__('Verification step', 'acemedia-login-block')}>
            <SelectControl label={__('Show at this step', 'acemedia-login-block')} value={attributes.step}
                options={[
                    {label: __('Login', 'acemedia-login-block'), value: 'login'},
                    {label: __('Two-factor authentication', 'acemedia-login-block'), value: 'two_factor'},
                    {label: __('Registration', 'acemedia-login-block'), value: 'registration'},
                    {label: __('Password reset', 'acemedia-login-block'), value: 'password_reset'},
                    {label: __('Passkey sign-in', 'acemedia-login-block'), value: 'passkey'},
                ]} onChange={step => setAttributes({step})}/>
        </PanelBody></InspectorControls>
        <Notice status="info" isDismissible={false}>{__('Turnstile verification. Enable this step in Settings → Login verification. It appears here when that step is reached.', 'acemedia-login-block')}</Notice>
    </div>,
    save: () => null,
});
