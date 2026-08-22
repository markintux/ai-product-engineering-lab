import * as tf from '@tensorflow/tfjs';

/**
 * Customer Churn Classifier
 *
 * This example demonstrates a simple multiclass classification problem
 * using TensorFlow.js.
 *
 * The goal is to classify customers into three churn risk categories:
 *
 * - low
 * - medium
 * - high
 *
 * IMPORTANT:
 * This dataset is intentionally small and synthetic because the purpose
 * of this project is to demonstrate the fundamentals of neural networks,
 * feature normalization, training and inference.
 *
 * This model should not be considered production-ready.
 */


/**
 * Labels predicted by the neural network.
 *
 * The position of each label must match the one-hot encoded outputs:
 *
 * low    -> [1, 0, 0]
 * medium -> [0, 1, 0]
 * high   -> [0, 0, 1]
 */
const churnLabels = [
    'low',
    'medium',
    'high',
];


/**
 * Training dataset.
 *
 * Features:
 *
 * visitFrequency:
 * Number of visits during a given period.
 *
 * averageTicket:
 * Average amount spent per visit.
 *
 * daysSinceLastVisit:
 * Number of days since the customer's last visit.
 *
 * purchaseCount:
 * Total number of purchases during the analyzed period.
 *
 * cashbackUsage:
 * Percentage of available cashback used by the customer.
 */
const customers = [
    {
        name: 'Alice',
        visitFrequency: 12,
        averageTicket: 85,
        daysSinceLastVisit: 2,
        purchaseCount: 20,
        cashbackUsage: 90,
        churnRisk: 'low',
    },
    {
        name: 'Bob',
        visitFrequency: 10,
        averageTicket: 70,
        daysSinceLastVisit: 4,
        purchaseCount: 17,
        cashbackUsage: 75,
        churnRisk: 'low',
    },
    {
        name: 'Carol',
        visitFrequency: 8,
        averageTicket: 65,
        daysSinceLastVisit: 7,
        purchaseCount: 13,
        cashbackUsage: 65,
        churnRisk: 'low',
    },

    {
        name: 'David',
        visitFrequency: 6,
        averageTicket: 55,
        daysSinceLastVisit: 15,
        purchaseCount: 9,
        cashbackUsage: 45,
        churnRisk: 'medium',
    },
    {
        name: 'Emma',
        visitFrequency: 5,
        averageTicket: 45,
        daysSinceLastVisit: 20,
        purchaseCount: 7,
        cashbackUsage: 35,
        churnRisk: 'medium',
    },
    {
        name: 'Frank',
        visitFrequency: 4,
        averageTicket: 50,
        daysSinceLastVisit: 25,
        purchaseCount: 6,
        cashbackUsage: 30,
        churnRisk: 'medium',
    },

    {
        name: 'Grace',
        visitFrequency: 2,
        averageTicket: 30,
        daysSinceLastVisit: 45,
        purchaseCount: 3,
        cashbackUsage: 15,
        churnRisk: 'high',
    },
    {
        name: 'Henry',
        visitFrequency: 1,
        averageTicket: 25,
        daysSinceLastVisit: 60,
        purchaseCount: 2,
        cashbackUsage: 10,
        churnRisk: 'high',
    },
    {
        name: 'Isabel',
        visitFrequency: 1,
        averageTicket: 20,
        daysSinceLastVisit: 90,
        purchaseCount: 1,
        cashbackUsage: 5,
        churnRisk: 'high',
    },
];


/**
 * Normalizes a number using Min-Max normalization.
 *
 * Neural networks usually perform better when input values share
 * a similar scale.
 *
 * Formula:
 *
 * normalizedValue = (value - min) / (max - min)
 *
 * The resulting value is generally between 0 and 1.
 */
function normalize(value, min, max) {
    return (value - min) / (max - min);
}


/**
 * Feature boundaries used during normalization.
 *
 * In a real application these values should normally be calculated
 * from the training dataset and persisted together with the model.
 *
 * The exact same normalization rules MUST be used during inference.
 */
const featureRanges = {
    visitFrequency: {
        min: 0,
        max: 15,
    },

    averageTicket: {
        min: 0,
        max: 100,
    },

    daysSinceLastVisit: {
        min: 0,
        max: 100,
    },

    purchaseCount: {
        min: 0,
        max: 25,
    },

    cashbackUsage: {
        min: 0,
        max: 100,
    },
};


/**
 * Converts a customer object into the numerical representation
 * expected by the neural network.
 *
 * Neural networks cannot directly understand objects, strings or
 * domain concepts such as "averageTicket".
 *
 * They receive numbers.
 *
 * Input order:
 *
 * [
 *   visitFrequency,
 *   averageTicket,
 *   daysSinceLastVisit,
 *   purchaseCount,
 *   cashbackUsage
 * ]
 */
function customerToTensorData(customer) {
    return [
        normalize(
            customer.visitFrequency,
            featureRanges.visitFrequency.min,
            featureRanges.visitFrequency.max
        ),

        normalize(
            customer.averageTicket,
            featureRanges.averageTicket.min,
            featureRanges.averageTicket.max
        ),

        normalize(
            customer.daysSinceLastVisit,
            featureRanges.daysSinceLastVisit.min,
            featureRanges.daysSinceLastVisit.max
        ),

        normalize(
            customer.purchaseCount,
            featureRanges.purchaseCount.min,
            featureRanges.purchaseCount.max
        ),

        normalize(
            customer.cashbackUsage,
            featureRanges.cashbackUsage.min,
            featureRanges.cashbackUsage.max
        ),
    ];
}


/**
 * Converts a churn risk label into one-hot encoding.
 *
 * Neural networks work with numerical outputs.
 *
 * low:
 * [1, 0, 0]
 *
 * medium:
 * [0, 1, 0]
 *
 * high:
 * [0, 0, 1]
 */
function churnRiskToOneHot(churnRisk) {
    const labelIndex = churnLabels.indexOf(churnRisk);

    if (labelIndex === -1) {
        throw new Error(`Unknown churn risk: ${churnRisk}`);
    }

    return churnLabels.map((_, index) => (
        index === labelIndex ? 1 : 0
    ));
}


/**
 * Creates and trains the neural network.
 */
async function trainModel(inputXs, outputYs) {
    const model = tf.sequential();

    /**
     * Hidden layer.
     *
     * inputShape: [5]
     *
     * Each customer is represented by five numerical features.
     *
     * units: 16
     *
     * The hidden layer contains 16 neurons.
     *
     * ReLU:
     *
     * ReLU stands for Rectified Linear Unit.
     *
     * It introduces non-linearity into the network, allowing the
     * model to learn relationships that are more complex than a
     * simple linear equation.
     *
     * Mathematically:
     *
     * ReLU(x) = max(0, x)
     */
    model.add(
        tf.layers.dense({
            inputShape: [5],
            units: 16,
            activation: 'relu',
        })
    );

    /**
     * Output layer.
     *
     * Three neurons represent the three possible classes:
     *
     * low
     * medium
     * high
     *
     * Softmax converts the raw output values into probabilities
     * whose sum is approximately 1.
     */
    model.add(
        tf.layers.dense({
            units: 3,
            activation: 'softmax',
        })
    );

    /**
     * The compile step defines how the neural network learns.
     *
     * Adam:
     *
     * Adam is an optimization algorithm responsible for adjusting
     * the model weights during training.
     *
     * categoricalCrossentropy:
     *
     * Appropriate loss function when:
     *
     * - there are multiple possible classes;
     * - only one class is correct;
     * - the output uses one-hot encoding.
     *
     * accuracy:
     *
     * Tracks how frequently the model predicts the correct class.
     */
    model.compile({
        optimizer: 'adam',
        loss: 'categoricalCrossentropy',
        metrics: ['accuracy'],
    });

    /**
     * Training process.
     *
     * An epoch represents one complete pass over the training dataset.
     *
     * shuffle helps prevent the network from learning patterns based
     * purely on the order of the training examples.
     */
    await model.fit(
        inputXs,
        outputYs,
        {
            epochs: 150,
            shuffle: true,
            verbose: 0,

            callbacks: {
                onEpochEnd: (epoch, logs) => {
                    /**
                     * We only display training information every
                     * 25 epochs to keep the console readable.
                     */
                    if ((epoch + 1) % 25 === 0) {
                        console.log(
                            `Epoch ${epoch + 1} | ` +
                            `loss: ${logs.loss.toFixed(4)} | ` +
                            `accuracy: ${logs.acc.toFixed(4)}`
                        );
                    }
                },
            },
        }
    );

    return model;
}


/**
 * Uses the trained model to predict the churn risk
 * of a new customer.
 */
async function predict(model, customer) {
    /**
     * Apply exactly the same preprocessing used during training.
     */
    const normalizedCustomer = customerToTensorData(customer);

    /**
     * TensorFlow expects a batch of inputs.
     *
     * Even when predicting a single customer, the input therefore
     * has the shape:
     *
     * [
     *   [feature1, feature2, feature3, feature4, feature5]
     * ]
     */
    const inputTensor = tf.tensor2d([
        normalizedCustomer,
    ]);

    /**
     * model.predict() returns a Tensor containing the probability
     * assigned to each class.
     */
    const predictionTensor = model.predict(inputTensor);

    const probabilities = await predictionTensor.array();

    /**
     * TensorFlow tensors allocate memory explicitly.
     *
     * Disposing tensors that are no longer needed becomes especially
     * important in long-running applications or repeated inference.
     */
    inputTensor.dispose();
    predictionTensor.dispose();

    return probabilities[0]
        .map((probability, index) => ({
            label: churnLabels[index],
            probability,
        }))
        .sort(
            (a, b) =>
                b.probability - a.probability
        );
}


/**
 * ----------------------------------------------------------------
 * DATA PREPARATION
 * ----------------------------------------------------------------
 */

/**
 * Convert domain objects into numerical feature arrays.
 */
const trainingInputs = customers.map(
    customer => customerToTensorData(customer)
);


/**
 * Convert expected churn classes into one-hot encoded arrays.
 */
const trainingOutputs = customers.map(
    customer => churnRiskToOneHot(customer.churnRisk)
);


/**
 * TensorFlow training tensors.
 *
 * xs = input features
 * ys = expected outputs / labels
 */
const inputXs = tf.tensor2d(trainingInputs);
const outputYs = tf.tensor2d(trainingOutputs);


/**
 * ----------------------------------------------------------------
 * MODEL TRAINING
 * ----------------------------------------------------------------
 */

console.log('Training model...\n');

const model = await trainModel(
    inputXs,
    outputYs
);

console.log('\nTraining completed.\n');


/**
 * ----------------------------------------------------------------
 * INFERENCE
 * ----------------------------------------------------------------
 *
 * This customer has:
 *
 * - relatively low visit frequency;
 * - moderate spending;
 * - several days since the last visit;
 * - few purchases;
 * - low cashback usage.
 *
 * The neural network should therefore tend toward a medium or
 * high churn risk depending on what it learned during training.
 */
const customerToAnalyze = {
    name: 'John',
    visitFrequency: 3,
    averageTicket: 42,
    daysSinceLastVisit: 35,
    purchaseCount: 5,
    cashbackUsage: 20,
};


const predictions = await predict(
    model,
    customerToAnalyze
);


/**
 * The first item represents the most probable class because
 * predictions were sorted by probability.
 */
const mostLikelyPrediction = predictions[0];


console.log(`Customer: ${customerToAnalyze.name}`);

console.log('\nChurn risk probabilities:');

predictions.forEach(prediction => {
    console.log(
        `${prediction.label}: ` +
        `${(prediction.probability * 100).toFixed(2)}%`
    );
});


console.log(
    `\nPredicted churn risk: ${mostLikelyPrediction.label.toUpperCase()}`
);


/**
 * Release the training tensors.
 *
 * The model remains available because its internal weights are
 * stored separately.
 */
inputXs.dispose();
outputYs.dispose();