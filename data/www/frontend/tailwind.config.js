/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        surface: '#f6f7f9',
        ink: '#101828',
      },
    },
  },
  plugins: [],
};
