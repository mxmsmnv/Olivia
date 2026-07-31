/** Build-only configuration. The generated CSS is committed for runtime use. */
module.exports = {
  content: ['./src/Build/OliviaViewGenerator.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['var(--olivia-font)'],
      },
      colors: {
        brand: {
          DEFAULT: 'rgb(var(--olivia-brand-rgb) / <alpha-value>)',
          600: 'rgb(var(--olivia-brand-rgb) / <alpha-value>)',
          700: 'rgb(var(--olivia-brand-700-rgb) / <alpha-value>)',
        },
      },
    },
  },
};
