import globals from 'globals'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'
import vueParser from 'vue-eslint-parser'
import prettierConfig from 'eslint-config-prettier'

export default [
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'public/build/**',
            'storage/**',
            '**/*.d.ts',
        ],
    },
    {
        files: ['resources/js/**/*.{ts,vue}'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tseslint.parser,
                extraFileExtensions: ['.vue'],
                ecmaVersion: 'latest',
                sourceType: 'module',
            },
            globals: {
                ...globals.browser,
                ...globals.es2022,
            },
        },
        plugins: {
            vue: pluginVue,
            '@typescript-eslint': tseslint.plugin,
        },
        rules: {
            // Vue
            ...pluginVue.configs['vue3-recommended'].rules,
            'vue/multi-word-component-names': 'off',
            'vue/component-tags-order': ['error', {
                order: ['script', 'template', 'style'],
            }],
            'vue/block-lang': ['error', {
                script: { lang: 'ts' },
            }],

            // TypeScript
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/no-unused-vars': ['warn', {
                argsIgnorePattern: '^_',
                varsIgnorePattern: '^_',
            }],
            '@typescript-eslint/explicit-function-return-type': 'off',
            '@typescript-eslint/consistent-type-imports': ['error', {
                prefer: 'type-imports',
            }],

            // General
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'no-debugger': 'error',
        },
    },
    prettierConfig,
]
